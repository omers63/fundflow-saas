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
        'suspected_posting_lines' => [
            [
                'reference_type' => Contribution::class,
                'reference_id' => 42,
                'transaction_id' => 101,
                'type' => 'credit',
                'amount' => 1000.0,
                'description' => 'Test posting line',
                'account_id' => 1,
                'account_type' => 'cash',
                'account_scope' => 'master',
                'member' => null,
                'transacted_at' => '2026-01-01 00:00:00',
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
        ->and(collect($sections)->firstWhere('format', 'metrics')['title'] ?? null)->toBe(__('Global totals and diagnostic counts'))
        ->and(collect($sections)->pluck('title'))->toContain(__('Suspected unbalanced postings'))
        ->and(collect($sections)->pluck('title'))->toContain(__('Suspected posting lines'))
        ->and(collect($sections)->pluck('title'))->toContain(__('Null-reference ledger lines'))
        ->and(collect($sections)->pluck('title'))->toContain(__('Net movement by account bucket'))
        ->and(collect($sections)->firstWhere('title', __('Suspected unbalanced postings'))['collapsible'] ?? false)->toBeTrue()
        ->and(collect($sections)->firstWhere('title', __('Suspected posting lines'))['collapsible'] ?? false)->toBeTrue()
        ->and(collect($sections)->firstWhere('title', __('Null-reference ledger lines'))['collapsible'] ?? false)->toBeTrue()
        ->and(collect($sections)->firstWhere('title', __('Global totals and diagnostic counts'))['collapsible'] ?? false)->toBeFalse()
        ->and(collect($sections)->firstWhere('title', __('Net movement by account bucket'))['table_align'] ?? null)->toBe('center');
});

test('snapshot presenter builds paired control diagnostic sections', function () {
    $sections = Presenter::checkDetailSections('paired_control_totals', [
        'severity' => 'warning',
        'cash_delta_abs' => 40.0,
        'fund_delta_abs' => 30.0,
        'cash_mirror_mismatches' => [
            [
                'reference_type' => Contribution::class,
                'reference_id' => 42,
                'master_amount' => 100.0,
                'member_amount' => 60.0,
                'mirror_delta' => 40.0,
                'master_lines' => 1,
                'member_lines' => 1,
                'sample_description' => 'Cash mismatch',
                'last_transacted_at' => '2026-01-02 00:00:00',
            ],
        ],
        'fund_mirror_mismatches' => [
            [
                'reference_type' => Contribution::class,
                'reference_id' => 42,
                'master_amount' => 80.0,
                'member_amount' => 50.0,
                'mirror_delta' => 30.0,
                'master_lines' => 1,
                'member_lines' => 1,
                'sample_description' => 'Fund mismatch',
                'last_transacted_at' => '2026-01-02 00:00:00',
            ],
        ],
        'cash_related_transactions' => [
            [
                'transaction_id' => 10,
                'transacted_at' => '2026-01-02 00:00:00',
                'type' => 'credit',
                'amount' => 100.0,
                'account_id' => 1,
                'account_type' => 'cash',
                'account_scope' => 'master',
                'member' => null,
                'linked_source' => 'Contribution #42',
                'description' => 'Cash mismatch',
            ],
        ],
        'fund_pool_adjustments' => [
            [
                'transaction_id' => 11,
                'transacted_at' => '2026-01-02 00:00:00',
                'type' => 'credit',
                'amount' => 25.0,
                'account_id' => 2,
                'account_type' => 'invest',
                'account_scope' => 'master',
                'adjustment_kind' => 'Reserve funding',
                'linked_source' => 'InvestDisbursement #5',
                'description' => 'Reserve funding',
            ],
        ],
        'resolution_hints' => [__('Review the cash and fund mismatch groups first.')],
    ]);

    expect(collect($sections)->pluck('title'))->toContain(__('Cash mirror mismatch groups'))
        ->and(collect($sections)->pluck('title'))->toContain(__('Fund mirror mismatch groups'))
        ->and(collect($sections)->pluck('title'))->toContain(__('Cash related transactions'))
        ->and(collect($sections)->pluck('title'))->toContain(__('Fund pool adjustments'))
        ->and(collect($sections)->firstWhere('title', __('Cash mirror mismatch groups'))['collapsible'] ?? false)->toBeTrue()
        ->and(collect($sections)->firstWhere('title', __('Fund pool adjustments'))['collapsible'] ?? false)->toBeTrue();
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

    expect($rows[0]['reference'])->toBeInstanceOf(HtmlString::class)
        ->and((string) $rows[0]['reference'])->toContain('contributions');
});

test('snapshot presenter links account ids to the correct account resource by scope', function () {
    Account::query()->delete();

    $masterFund = Account::create([
        'type' => 'fund',
        'name' => 'Master Fund',
        'balance' => 0,
        'is_master' => true,
    ]);

    $member = Member::factory()->create();

    $memberCash = Account::create([
        'type' => 'cash',
        'name' => 'Member Cash',
        'balance' => 0,
        'is_master' => false,
        'member_id' => $member->id,
    ]);

    $masterLink = Presenter::detailCellValue('account_id', $masterFund->id);
    $memberLink = Presenter::detailCellValue('account_id', $memberCash->id);

    expect($masterLink)->toBeInstanceOf(HtmlString::class)
        ->and((string) $masterLink)->toContain('master-accounts/'.$masterFund->id)
        ->and($memberLink)->toBeInstanceOf(HtmlString::class)
        ->and((string) $memberLink)->toContain('member-accounts/'.$memberCash->id);
});

test('snapshot presenter formats global trial counts as numbers not currency', function () {
    $metrics = Presenter::checkMetricRows('global_trial', [
        'severity' => 'warning',
        'sum_credits' => 1700.5,
        'sum_debits' => 200.0,
        'delta' => 1500.5,
        'unbalanced_posting_group_count' => 3,
        'null_reference_line_count' => 2,
        'null_reference_credits' => 1500.5,
        'null_reference_debits' => 200.0,
        'null_reference_delta' => 1300.5,
    ]);

    $flat = collect($metrics)->flatMap(fn (array $row): array => $row);

    expect(array_key_first($metrics[0]))->toBe(__('Σ credits'))
        ->and(array_key_first($metrics[1]))->toBe(__('Σ debits'))
        ->and($flat[__('Unbalanced posting groups')])->toBe('3')
        ->and($flat[__('Null-reference lines')])->toBe('2')
        ->and($flat[__('Null-reference credits')])->toContain('1,500')
        ->and($flat[__('Null-reference debits')])->toContain('200')
        ->and($flat[__('How to read these metrics')])
        ->toBe(__('These figures are book-wide aggregates across all ledger lines in this snapshot. They are not totals for one linked source or one posting group.'));
});

test('snapshot presenter organizes paired control totals metrics and formats pool values as money', function () {
    $metrics = Presenter::checkMetricRows('paired_control_totals', [
        'severity' => 'warning',
        'tolerance' => 0.03,
        'master_cash_balance' => 2000.0,
        'sum_member_cash' => 1800.0,
        'cash_delta' => 200.0,
        'cash_delta_abs' => 200.0,
        'master_fund_balance' => 1300.0,
        'master_invest_from_fund_credits' => 500.0,
        'master_expense_from_fund_credits' => 300.0,
        'master_invest_return_to_fund_credits' => 300.0,
        'master_fund_pool' => 1800.0,
        'sum_member_fund' => 1700.0,
        'fund_delta' => 100.0,
        'fund_delta_abs' => 100.0,
        'master_invest_balance' => 500.0,
        'master_expense_balance' => 300.0,
        'master_fees_balance' => 0.0,
        'master_suspense_balance' => 0.0,
        'master_bank_balance' => 2500.0,
        'note' => 'Pool note',
    ]);

    $labels = array_map(
        static fn (array $row): string => (string) array_key_first($row),
        $metrics,
    );
    $flat = collect($metrics)->flatMap(fn (array $row): array => $row);

    expect(array_slice($labels, 0, 5))->toBe([
        __('Tolerance'),
        __('Master cash balance'),
        __('Sum member cash'),
        __('Cash delta'),
        __('Absolute cash delta'),
    ])->and($flat[__('Tolerance')])->toContain('0.03')
        ->and($flat[__('Sum member cash')])->toContain('1,800')
        ->and($flat[__('Adjusted master fund pool')])->toContain('1,800')
        ->and($flat[__('Absolute fund pool delta')])->toContain('100')
        ->and($labels)->toContain(__('Note'));
});
