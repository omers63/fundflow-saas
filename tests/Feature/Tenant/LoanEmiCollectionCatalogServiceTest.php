<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $this->accounting = app(AccountingService::class);
    $this->catalog = app(LoanEmiCollectionCatalogService::class);
    $this->cycles = app(ContributionCycleService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('pending members query includes borrowers with open period emis', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();

    $member = Member::create([
        'member_number' => 'EMI-CAT-1',
        'name' => 'Catalog Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::create($year, $month, 10),
        'status' => 'pending',
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => Carbon::create($year, $month + 1, 10),
        'status' => 'pending',
    ]);

    expect($this->catalog->pendingMemberCount($month, $year))->toBe(1)
        ->and($this->catalog->pendingInstallmentCountForMember($member, $month, $year))->toBe(1);
});

test('apply installments for period collects emis due in that cycle only', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();

    $member = Member::create([
        'member_number' => 'EMI-BATCH-1',
        'name' => 'Batch Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::create($year, $month, 10),
        'status' => 'pending',
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => Carbon::create($year, $month + 1, 10),
        'status' => 'pending',
    ]);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($member->cashAccount, 1500, 'Deposit'),
    );

    $results = $this->catalog->applyInstallmentsForPeriod($month, $year);

    expect($results['applied']->pluck('id')->all())->toContain($member->id)
        ->and(LoanInstallment::query()->where('installment_number', 1)->value('status'))->toBe('paid')
        ->and(LoanInstallment::query()->where('installment_number', 2)->value('status'))->toBe('pending');
});

test('apply for member collects open period emi from cash', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();

    $member = Member::create([
        'member_number' => 'EMI-CAT-2',
        'name' => 'Paying Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::create($year, $month, 10),
        'status' => 'pending',
    ]);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($member->cashAccount, 1500, 'Deposit'),
    );

    $outcome = $this->catalog->applyForMember($member->fresh(), $month, $year);

    expect($outcome)->toBe('collected')
        ->and(LoanInstallment::query()->where('loan_id', $loan->id)->value('status'))->toBe('paid');
});

test('collected installment count reflects paid emis in open period', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();

    $member = Member::create([
        'member_number' => 'EMI-COL-1',
        'name' => 'Collected Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::create($year, $month, 10),
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    expect($this->catalog->collectedInstallmentCount($month, $year))->toBe(1);
});

test('emi collection lists use labelled cycle from due date not payment window', function () {
    Setting::set('contribution', 'cycle_start_day', '6');

    $member = Member::create([
        'member_number' => 'EMI-CYCLE-4',
        'name' => 'Cycle Label Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 11_000,
        'amount_requested' => 11_000,
        'amount_approved' => 11_000,
        'amount_disbursed' => 11_000,
        'interest_rate' => 10,
        'term_months' => 20,
        'monthly_repayment' => 5500,
        'total_repaid' => 5500,
        'status' => 'active',
        'applied_at' => Carbon::parse('2024-04-08'),
        'disbursed_at' => Carbon::parse('2024-04-08'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 17,
        'amount' => 5500,
        'due_date' => Carbon::parse('2025-10-05'),
        'status' => 'paid',
        'paid_at' => Carbon::parse('2025-10-27'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 18,
        'amount' => 5500,
        'due_date' => Carbon::parse('2025-11-05'),
        'status' => 'pending',
    ]);

    expect($this->catalog->collectedInstallmentsQuery(9, 2025)->whereHas('loan', fn ($q) => $q->where('member_id', $member->id))->exists())->toBeTrue()
        ->and($this->catalog->collectedInstallmentsQuery(10, 2025)->whereHas('loan', fn ($q) => $q->where('member_id', $member->id))->exists())->toBeFalse()
        ->and($this->catalog->membersWithCollectableEmisQuery(10, 2025)->where('id', $member->id)->exists())->toBeTrue()
        ->and($this->catalog->membersWithCollectableEmisQuery(9, 2025)->where('id', $member->id)->exists())->toBeFalse();
});

test('emi arrears installment count includes unpaid installments before selected cycle only', function () {
    Setting::set('contribution', 'cycle_start_day', '6');

    Carbon::setTestNow(Carbon::parse('2025-10-15'));

    $member = Member::create([
        'member_number' => 'EMI-ARR-1',
        'name' => 'Arrears Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2024-01-01'),
        'disbursed_at' => Carbon::parse('2024-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2025-09-05'),
        'status' => 'pending',
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => Carbon::parse('2025-11-05'),
        'status' => 'pending',
    ]);

    expect($this->catalog->emiArrearsInstallmentCount(10, 2025, true))->toBe(1)
        ->and($this->catalog->emiArrearsInstallmentsQuery(10, 2025, true)->pluck('installment_number'))->toContain(1)
        ->and($this->catalog->emiArrearsInstallmentsQuery(10, 2025, true)->pluck('installment_number'))->not->toContain(2);
});

test('primary collectable loan resolver returns loan for outstanding column', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();

    $member = Member::create([
        'member_number' => 'EMI-OUT-1',
        'name' => 'Outstanding Column Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::create($year, $month, 10),
        'status' => 'pending',
    ]);

    expect($this->catalog->primaryCollectableLoanForMember($member, $month, $year)?->is($loan))->toBeTrue()
        ->and($this->catalog->outstandingLoanBalanceForMember($member, $month, $year))->toBe($loan->getOutstandingBalance());
});
