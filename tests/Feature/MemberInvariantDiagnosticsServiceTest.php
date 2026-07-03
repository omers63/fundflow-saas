<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Services\AccountingService;
use App\Services\Loans\LoanLedgerService;
use App\Services\MemberInvariantDiagnosticsService;
use App\Support\BusinessDay;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    Account::query()->delete();
    Member::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100_000, 'is_master' => true]);
});

test('member cash drift diagnostics explain legacy paired contribution cash legs', function (): void {
    $member = Member::create([
        'member_number' => 'DIAG-001',
        'name' => 'Diagnostics Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $member->refresh();

    $contribution = Contribution::factory()->posted()->create([
        'member_id' => $member->id,
        'amount' => 500,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    $cash = $member->cashAccount;
    $at = BusinessDay::now();
    $accounting = app(AccountingService::class);

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $cash, $contribution, $at, $member): void {
        $accounting->credit($cash, 500, __('Contribution — test'), $contribution, $at, $member->id);
    });

    $accounting->debit($cash, 500, __('Contribution — test'), $contribution, $at, $member->id);
    $accounting->credit($member->fundAccount, 500, __('Contribution — test'), $contribution, $at, $member->id);

    $exception = ReconciliationException::create([
        'exception_code' => 'MEMBER_CASH_DRIFT',
        'domain' => 'master_account',
        'severity' => 'medium',
        'amount_delta' => 500,
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
        'affected_entities' => ['member_id' => $member->id],
    ]);

    $diagnostics = app(MemberInvariantDiagnosticsService::class)->forException($exception);

    expect($diagnostics)->not->toBeNull()
        ->and($diagnostics['pool'])->toBe('cash')
        ->and($diagnostics['actual'])->toBe(0.0)
        ->and($diagnostics['expected'])->toBe(0.0)
        ->and($diagnostics['drift'])->toBe(0.0)
        ->and($diagnostics['legacy_import_pattern'])->toBeFalse()
        ->and($diagnostics['adjusted_expected'])->toBe(0.0)
        ->and($diagnostics['adjusted_drift'])->toBe(0.0)
        ->and($diagnostics['suggested_correction']['action'])->toBe('none')
        ->and(collect($diagnostics['formula_lines'])->pluck('label'))->toContain(__('Contributions credited'))
        ->and($diagnostics['mismatch_transactions'])->toBeEmpty();
});

test('member cash drift diagnostics suggest mirror correction for genuine imbalance', function (): void {
    $member = Member::create([
        'member_number' => 'DIAG-002',
        'name' => 'Imbalance Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $member->refresh();

    AccountingService::withoutMemberCashCollection(function () use ($member): void {
        app(AccountingService::class)->creditMemberCashWithMasterMirror(
            $member->cashAccount,
            250,
            __('Manual deposit'),
            __('(test mirror)'),
            null,
            BusinessDay::now(),
            $member->id,
        );
    });

    $member->refresh();

    $exception = ReconciliationException::create([
        'exception_code' => 'MEMBER_CASH_DRIFT',
        'domain' => 'master_account',
        'severity' => 'medium',
        'amount_delta' => 250,
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
        'affected_entities' => ['member_id' => $member->id],
    ]);

    $diagnostics = app(MemberInvariantDiagnosticsService::class)->forException($exception);

    expect($diagnostics['actual'])->toBe(250.0)
        ->and($diagnostics['expected'])->toBe(0.0)
        ->and($diagnostics['drift'])->toBe(250.0)
        ->and($diagnostics['legacy_import_pattern'])->toBeFalse()
        ->and($diagnostics['suggested_correction']['action'])->toBe('post_correction')
        ->and($diagnostics['suggested_correction']['direction'])->toBe('debit')
        ->and($diagnostics['suggested_correction']['amount'])->toBe(250.0);
});

test('member cash drift diagnostics include loan repayment cash legs in formula', function (): void {
    $member = Member::create([
        'member_number' => 'DIAG-003',
        'name' => 'Repayment Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $member->refresh();

    $loan = Loan::factory()->create([
        'member_id' => $member->id,
        'amount_disbursed' => 1000,
        'status' => 'active',
    ]);

    $repayment = LoanRepayment::factory()->create([
        'loan_id' => $loan->id,
        'amount' => 300,
    ]);

    $cash = $member->cashAccount;
    $at = BusinessDay::now();
    $accounting = app(AccountingService::class);

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $cash, $repayment, $at, $member): void {
        $accounting->credit($cash, 300, __('Repayment import'), $repayment, $at, $member->id);
        $accounting->debit($cash, 300, __('Repayment import'), $repayment, $at, $member->id);
    });

    $exception = ReconciliationException::create([
        'exception_code' => 'MEMBER_CASH_DRIFT',
        'domain' => 'master_account',
        'severity' => 'medium',
        'amount_delta' => 1,
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
        'affected_entities' => ['member_id' => $member->id],
    ]);

    $diagnostics = app(MemberInvariantDiagnosticsService::class)->forException($exception);

    expect($diagnostics['actual'])->toBe(0.0)
        ->and($diagnostics['expected'])->toBe(0.0)
        ->and($diagnostics['drift'])->toBe(0.0)
        ->and($diagnostics['legacy_import_pattern'])->toBeFalse()
        ->and(collect($diagnostics['formula_lines'])->pluck('label'))
        ->toContain(__('Loan repayment cash credited'))
        ->and(collect($diagnostics['formula_lines'])->pluck('label'))
        ->toContain(__('Loan repayment cash debited'))
        ->and($diagnostics['uncounted_flows'])->toBeEmpty();
});

test('member fund drift diagnostics include legacy loan repayment fund credits in formula', function (): void {
    $member = Member::create([
        'member_number' => 'DIAG-004',
        'name' => 'Legacy Fund Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $member->refresh();

    $loan = Loan::factory()->create([
        'member_id' => $member->id,
        'amount_disbursed' => 6000,
        'member_portion' => 6000,
        'master_portion' => 0,
        'status' => 'active',
    ]);

    app(LoanLedgerService::class)->ensureLoanAccount($loan);
    app(AccountingService::class)->debit($member->fundAccount, 6000, __('Loan disbursement'), $loan, BusinessDay::now(), $member->id);

    $repayment = LoanRepayment::factory()->create([
        'loan_id' => $loan->id,
        'amount' => 1800,
    ]);

    app(LoanLedgerService::class)->postImportedLoanRepaymentWithCashFlow(
        $loan->fresh(),
        $repayment,
        1800,
        BusinessDay::now(),
    );

    $exception = ReconciliationException::create([
        'exception_code' => 'MEMBER_FUND_DRIFT',
        'domain' => 'master_account',
        'severity' => 'medium',
        'amount_delta' => 1800,
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
        'affected_entities' => ['member_id' => $member->id],
    ]);

    $diagnostics = app(MemberInvariantDiagnosticsService::class)->forException($exception);

    expect($diagnostics['pool'])->toBe('fund')
        ->and($diagnostics['actual'])->toBe(-4200.0)
        ->and($diagnostics['expected'])->toBe(-4200.0)
        ->and($diagnostics['drift'])->toBe(0.0)
        ->and($diagnostics['legacy_import_pattern'])->toBeFalse()
        ->and(collect($diagnostics['formula_lines'])->pluck('label'))
        ->toContain(__('EMI repayments (legacy import)'))
        ->and($diagnostics['suggested_correction']['action'])->toBe('none')
        ->and($diagnostics['uncounted_flows'])->toBeEmpty();
});
