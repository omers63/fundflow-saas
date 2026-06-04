<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanInstallmentCollectionService;
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
    $this->collection = app(LoanInstallmentCollectionService::class);
    $this->cycles = app(ContributionCycleService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('cash increase only collects open period and arrears not future installments', function () {
    [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();

    expect([$openMonth, $openYear])->toBe([6, 2026]);

    $member = Member::create([
        'member_number' => 'MEM-EMI-1',
        'name' => 'Borrower One',
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
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    foreach ([
        ['month' => 6, 'year' => 2026, 'number' => 1],
        ['month' => 7, 'year' => 2026, 'number' => 2],
        ['month' => 8, 'year' => 2026, 'number' => 3],
        ['month' => 9, 'year' => 2026, 'number' => 4],
    ] as $period) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $period['number'],
            'amount' => 1000,
            'due_date' => Carbon::create($period['year'], $period['month'], 15),
            'status' => 'pending',
        ]);
    }

    AccountingService::withoutMemberCashCollection(
        fn() => $this->accounting->credit($member->cashAccount, 5000, 'Large deposit'),
    );

    $this->collection->onMemberCashIncreased($member->fresh());

    $statuses = LoanInstallment::query()
        ->where('loan_id', $loan->id)
        ->orderBy('installment_number')
        ->pluck('status', 'installment_number')
        ->all();

    expect($statuses[1])->toBe('paid')
        ->and($statuses[2])->toBe('pending')
        ->and($statuses[3])->toBe('pending')
        ->and($statuses[4])->toBe('pending');
});

test('mar 5 due date is collected in february open period not march', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-06'));
    [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();

    expect([$openMonth, $openYear])->toBe([2, 2026]);

    $member = Member::create([
        'member_number' => 'MEM-EMI-MAR5',
        'name' => 'Mar 5 Borrower',
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
        'due_date' => Carbon::parse('2026-03-05'),
        'status' => 'pending',
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-03-06'),
        'status' => 'pending',
    ]);

    AccountingService::withoutMemberCashCollection(
        fn() => $this->accounting->credit($member->cashAccount, 3000, 'Deposit'),
    );

    $this->collection->onMemberCashIncreased($member->fresh());

    expect(LoanInstallment::query()->where('installment_number', 1)->value('status'))->toBe('paid')
        ->and(LoanInstallment::query()->where('installment_number', 2)->value('status'))->toBe('pending');
});

test('cash increase for a specific period only collects that installment', function () {
    $member = Member::create([
        'member_number' => 'MEM-EMI-2',
        'name' => 'Borrower Two',
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

    foreach ([
        ['month' => 5, 'year' => 2026, 'number' => 1],
        ['month' => 6, 'year' => 2026, 'number' => 2],
    ] as $period) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $period['number'],
            'amount' => 1000,
            'due_date' => Carbon::create($period['year'], $period['month'], 15),
            'status' => 'pending',
        ]);
    }

    AccountingService::withoutMemberCashCollection(
        fn() => $this->accounting->credit($member->cashAccount, 3000, 'Deposit'),
    );

    $this->collection->onMemberCashIncreasedForPeriod($member->fresh(), 6, 2026);

    $installmentOne = LoanInstallment::query()->where('loan_id', $loan->id)->where('installment_number', 1)->first();
    $installmentTwo = LoanInstallment::query()->where('loan_id', $loan->id)->where('installment_number', 2)->first();

    expect($installmentOne?->status)->toBe('pending')
        ->and($installmentTwo?->status)->toBe('paid');
});
