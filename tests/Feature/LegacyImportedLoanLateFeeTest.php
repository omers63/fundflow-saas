<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Loans\LoanInstallmentCollectionService;
use App\Support\LegacyImportedLoan;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();
    LoanRepayment::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 1_000_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 1_000_000, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $this->accounting = app(AccountingService::class);
    $this->collection = app(LoanInstallmentCollectionService::class);
    $this->delinquency = app(LoanDelinquencyService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('legacy imported loans skip automated overdue marking and late fee collection', function () {
    $member = Member::create([
        'member_number' => 'LEG-132',
        'name' => 'Legacy Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2020-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2022-01-01'),
        'disbursed_at' => Carbon::parse('2022-01-01'),
    ]);

    LoanRepayment::query()->create([
        'loan_id' => $loan->id,
        'amount' => 1000,
        'paid_at' => Carbon::parse('2022-02-01'),
        'notes' => 'Legacy migration loan repayment [legacy-import:LEG-132|legacy@fund.test|2022-02-01|1000|loan_repayment|]',
    ]);

    $installment = LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2022-03-05'),
        'status' => 'pending',
    ]);

    expect(LegacyImportedLoan::isLoan($loan))->toBeTrue();

    $this->delinquency->markOverdueInstallments();

    $installment->refresh();
    expect($installment->status)->toBe('pending')
        ->and((float) ($installment->late_fee_amount ?? 0))->toBe(0.0);

    AccountingService::withoutMemberCashCollection(
        fn() => $this->accounting->credit($member->cashAccount, 5000, 'Deposit'),
    );

    $this->collection->onMemberCashIncreased($member->fresh());

    $installment->refresh();
    expect($installment->status)->toBe('paid')
        ->and((float) ($installment->late_fee_amount ?? 0))->toBe(0.0);
});

test('legacy imported loan waiver clears stored late fee fields', function () {
    $member = Member::create([
        'member_number' => 'LEG-WAIVE',
        'name' => 'Legacy Waive',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2020-01-01'),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 0,
        'term_months' => 5,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'completed',
        'applied_at' => Carbon::parse('2022-01-01'),
        'disbursed_at' => Carbon::parse('2022-01-01'),
    ]);

    LoanRepayment::query()->create([
        'loan_id' => $loan->id,
        'amount' => 1000,
        'paid_at' => Carbon::parse('2022-02-01'),
        'notes' => 'صف مصنّف ترحيل البيانات التاريخية 1',
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2022-03-05'),
        'status' => 'paid',
        'paid_at' => Carbon::parse('2025-11-05'),
        'late_fee_amount' => 150,
        'is_late' => true,
        'late_fee_tier' => 1,
        'overdue_since' => Carbon::parse('2022-04-01'),
    ]);

    expect(LegacyImportedLoan::waiveAutomatedLateFees($loan))->toBe(1);

    $installment = LoanInstallment::query()->where('loan_id', $loan->id)->first();
    expect((float) ($installment->late_fee_amount ?? 0))->toBe(0.0)
        ->and($installment->is_late)->toBeFalse()
        ->and($installment->late_fee_tier)->toBe(0)
        ->and($installment->overdue_since)->toBeNull();
});
