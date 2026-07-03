<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Support\Reconciliation\ReconciliationSnapshotPresenter as Presenter;
use Filament\Facades\Filament;
use Illuminate\Support\HtmlString;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
});

test('snapshot presenter orders checks by severity', function () {
    $ordered = Presenter::orderedChecks([
        'ledger_balances' => ['severity' => 'ok'],
        'global_trial' => ['severity' => 'warning'],
        'orphan_loan_accounts' => ['severity' => 'critical'],
    ]);

    expect(array_column($ordered, 'key'))->toBe([
        'orphan_loan_accounts',
        'global_trial',
        'ledger_balances',
    ]);
});

test('snapshot presenter builds loan mismatch detail sections', function () {
    $sections = Presenter::checkDetailSections('active_loans_schedule_vs_ledger', [
        'severity' => 'warning',
        'mismatch_count' => 1,
        'mismatches' => [[
            'loan_id' => 180,
            'member' => 'Test Member',
            'ledger_outstanding' => 35_000.0,
            'ledger_expected' => 35_000.0,
            'scheduled_outstanding' => 36_000.0,
            'partial_paid_ahead' => 1_000.0,
            'delta' => 0.0,
        ]],
    ]);

    expect($sections)->not->toBeEmpty()
        ->and($sections[0]['format'])->toBe('metrics')
        ->and($sections[1]['title'])->toBe(__('Mismatch details'))
        ->and($sections[1]['rows'][0]['loan_id'])->toBe(180);
});

test('snapshot presenter formats mode labels', function () {
    expect(Presenter::modeLabel(ReconciliationSnapshot::MODE_REALTIME))->toBe(__('Real-time'))
        ->and(Presenter::modeLabel(ReconciliationSnapshot::MODE_DAILY))->toBe(__('Daily'));
});

test('snapshot presenter builds global trial diagnostic sections', function () {
    $sections = Presenter::checkDetailSections('global_trial', [
        'severity' => 'warning',
        'sum_credits' => 1000.0,
        'sum_debits' => 500.0,
        'delta' => 500.0,
        'unbalanced_posting_group_count' => 1,
        'null_reference_line_count' => 2,
        'null_reference_lines' => [
            [
                'transaction_id' => 11,
                'type' => 'credit',
                'amount' => 300.0,
                'description' => 'Manual credit',
                'account_id' => 1,
                'account_type' => 'cash',
                'account_scope' => 'master',
                'member' => null,
                'transacted_at' => '2026-01-02 00:00:00',
            ],
            [
                'transaction_id' => 12,
                'type' => 'debit',
                'amount' => 100.0,
                'description' => 'Manual debit',
                'account_id' => 1,
                'account_type' => 'cash',
                'account_scope' => 'master',
                'member' => null,
                'transacted_at' => '2026-01-01 00:00:00',
            ],
        ],
        'resolution_hints' => [__('Each posting group should balance.')],
        'suspected_postings' => [
            [
                'reference_type' => Contribution::class,
                'reference_id' => 42,
                'sum_credits' => 1000.0,
                'sum_debits' => 500.0,
                'posting_delta' => 500.0,
                'line_count' => 2,
                'sample_description' => 'Test posting',
                'first_transacted_at' => '2026-01-01 00:00:00',
            ],
        ],
        'net_by_account_type' => [
            [
                'account_type' => 'cash',
                'scope' => 'master',
                'sum_credits' => 1000.0,
                'sum_debits' => 500.0,
                'net_delta' => 500.0,
            ],
        ],
    ]);

    expect(collect($sections)->pluck('title'))->toContain(__('How to investigate'))
        ->and(collect($sections)->firstWhere('format', 'hints')['format'] ?? null)->toBe('hints')
        ->and(collect($sections)->pluck('title'))->toContain(__('Suspected unbalanced postings'))
        ->and(collect($sections)->pluck('title'))->toContain(__('Null-reference ledger lines'))
        ->and(collect($sections)->pluck('title'))->toContain(__('Trial drift by account type'))
        ->and(collect($sections)->firstWhere('title', __('Suspected unbalanced postings'))['collapsible'] ?? false)->toBeTrue()
        ->and(collect($sections)->firstWhere('title', __('Null-reference ledger lines'))['collapsible'] ?? false)->toBeTrue()
        ->and(collect($sections)->firstWhere('title', __('Metrics'))['collapsible'] ?? false)->toBeFalse()
        ->and(collect($sections)->firstWhere('title', __('Trial drift by account type'))['table_align'] ?? null)->toBe('center');
});

test('snapshot presenter links suspected posting references to admin destinations', function () {
    Account::query()->delete();
    Account::create(['type' => 'invest', 'name' => 'Master Invest', 'balance' => 0, 'is_master' => true]);

    $member = Member::factory()->create(['name' => 'Posting Link Member']);
    $loan = Loan::factory()->create(['member_id' => $member->id]);
    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);
    $repayment = LoanRepayment::create([
        'loan_id' => $loan->id,
        'amount' => 500,
        'paid_at' => now(),
    ]);
    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => now()->startOfMonth(),
        'amount' => 1000,
        'amount_due' => 1000,
        'status' => 'pending',
    ]);
    $posting = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 250,
        'status' => 'pending',
    ]);
    $cashOut = CashOutRequest::create([
        'member_id' => $member->id,
        'amount' => 100,
        'status' => 'pending',
    ]);

    $cases = [
        [Contribution::class, $contribution->id, 'contributions'],
        [Loan::class, $loan->id, 'loans'],
        [LoanInstallment::class, $installment->id, 'loans'],
        [LoanRepayment::class, $repayment->id, 'loans'],
        [FundPosting::class, $posting->id, 'fund-postings'],
        [Member::class, $member->id, 'members'],
        [CashOutRequest::class, $cashOut->id, 'cash-out-requests'],
        [InvestDisbursement::class, 99, 'master-accounts'],
    ];

    foreach ($cases as [$type, $id, $pathFragment]) {
        $link = Presenter::referenceLink($type, $id);

        expect($link)->toBeInstanceOf(HtmlString::class)
            ->and((string) $link)->toContain('<a href=')
            ->and((string) $link)->toContain($pathFragment);
    }

    expect((string) Presenter::referenceLink(LoanInstallment::class, $installment->id))
        ->toContain('loans')
        ->toContain((string) $loan->id);
});

test('snapshot presenter enriches suspected postings with linked posting column', function () {
    $member = Member::factory()->create();
    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => now()->startOfMonth(),
        'amount' => 1000,
        'amount_due' => 1000,
        'status' => 'pending',
    ]);

    $rows = Presenter::enrichSuspectedPostingRows([
        [
            'reference_type' => Contribution::class,
            'reference_id' => $contribution->id,
            'sum_credits' => 1000,
            'sum_debits' => 500,
            'posting_delta' => 500,
            'line_count' => 2,
        ],
    ]);

    expect($rows[0]['posting'])->toBeInstanceOf(HtmlString::class)
        ->and((string) $rows[0]['posting'])->toContain('contributions');
});

test('snapshot presenter formats global trial counts as numbers not currency', function () {
    $metrics = Presenter::checkMetricRows('global_trial', [
        'severity' => 'warning',
        'unbalanced_posting_group_count' => 3,
        'null_reference_line_count' => 2,
        'null_reference_credits' => 1500.5,
        'null_reference_debits' => 200.0,
        'null_reference_delta' => 1300.5,
    ]);

    $flat = collect($metrics)->flatMap(fn (array $row): array => $row);

    expect($flat[__('Unbalanced posting groups')])->toBe('3')
        ->and($flat[__('Null-reference lines')])->toBe('2')
        ->and($flat[__('Null-reference credits')])->toContain('1,500')
        ->and($flat[__('Null-reference debits')])->toContain('200');
});
