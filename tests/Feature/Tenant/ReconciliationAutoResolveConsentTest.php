<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\Loans\LoanLedgerService;
use App\Services\ReconciliationResolutionService;
use App\Services\ReconciliationService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    ReconciliationException::query()->delete();
    Transaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
});

function createPaidInstallmentWithoutLoanLedgerCredit(): array
{
    $member = Member::create([
        'member_number' => 'RECON-EMI-'.uniqid(),
        'name' => 'EMI Recon Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 3000,
        'amount_requested' => 3000,
        'amount_approved' => 3000,
        'amount_disbursed' => 3000,
        'member_portion' => 3000,
        'master_portion' => 0,
        'interest_rate' => 0,
        'term_months' => 1,
        'monthly_repayment' => 3000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => now()->subMonths(2),
        'approved_at' => now()->subMonths(2),
        'applied_at' => now()->subMonths(2),
        'installments_count' => 1,
    ]);

    app(LoanLedgerService::class)->ensureLoanAccount($loan);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 3000,
        'due_date' => now()->subMonth()->toDateString(),
        'status' => 'paid',
        'paid_at' => now()->subMonth(),
    ]);

    return [$loan->fresh(), $installment->fresh()];
}

test('nightly reconciliation raises but does not auto-post missing EMI ledger entries', function () {
    [$loan, $installment] = createPaidInstallmentWithoutLoanLedgerCredit();

    $loanAccount = Account::query()->where('loan_id', $loan->id)->where('type', 'loan')->first();
    $beforeCount = Transaction::query()->where('account_id', $loanAccount->id)->count();

    app(ReconciliationService::class)->runNightlyBatch();

    $exception = ReconciliationException::query()
        ->where('exception_code', 'EMI_COLLECTED_LEDGER_MISSING')
        ->where('affected_entities->installment_id', $installment->id)
        ->first();

    expect($exception)->not->toBeNull()
        ->and($exception->status)->toBe(ReconciliationException::STATUS_OPEN)
        ->and(Transaction::query()->where('account_id', $loanAccount->id)->count())->toBe($beforeCount);
});

test('admin retry auto-resolve posts missing EMI ledger entries', function () {
    [$loan, $installment] = createPaidInstallmentWithoutLoanLedgerCredit();

    $loanAccount = Account::query()->where('loan_id', $loan->id)->where('type', 'loan')->first();

    app(ReconciliationService::class)->runNightlyBatch();

    $exception = ReconciliationException::query()
        ->where('exception_code', 'EMI_COLLECTED_LEDGER_MISSING')
        ->where('affected_entities->installment_id', $installment->id)
        ->firstOrFail();

    $resolved = app(ReconciliationResolutionService::class)->retryAutoResolve($exception);

    expect($resolved)->toBeTrue()
        ->and($exception->fresh()->status)->toBe(ReconciliationException::STATUS_RESOLVED)
        ->and(
            Transaction::query()
                ->where('account_id', $loanAccount->id)
                ->where('type', 'credit')
                ->where('reference_type', LoanInstallment::class)
                ->where('reference_id', $installment->id)
                ->exists()
        )->toBeTrue();
});

test('legacy loan repayments keyed to LoanRepayment do not raise EMI collected ledger missing', function () {
    $member = Member::create([
        'member_number' => 'RECON-LEGACY-EMI',
        'name' => 'Legacy EMI Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(3),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 3000,
        'amount_requested' => 3000,
        'amount_approved' => 3000,
        'amount_disbursed' => 3000,
        'member_portion' => 3000,
        'master_portion' => 0,
        'interest_rate' => 0,
        'term_months' => 1,
        'monthly_repayment' => 3000,
        'total_repaid' => 0,
        'status' => 'completed',
        'disbursed_at' => now()->subYear(),
        'approved_at' => now()->subYear(),
        'applied_at' => now()->subYear(),
        'installments_count' => 1,
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 3000,
        'due_date' => now()->subMonths(6)->toDateString(),
        'status' => 'paid',
        'paid_at' => now()->subMonths(6),
    ]);

    $repayment = LoanRepayment::create([
        'loan_id' => $loan->id,
        'amount' => 3000,
        'paid_at' => now()->subMonths(6),
        'notes' => 'legacy-import:test',
    ]);

    app(LoanLedgerService::class)->postImportedLoanRepaymentWithCashFlow(
        $loan->fresh(),
        $repayment,
        3000,
        now()->subMonths(6),
    );

    app(ReconciliationService::class)->runNightlyBatch();

    expect(ReconciliationException::query()
        ->where('exception_code', 'EMI_COLLECTED_LEDGER_MISSING')
        ->where('affected_entities->loan_id', $loan->id)
        ->exists())->toBeFalse();
});
