<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
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
