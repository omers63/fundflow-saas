<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanRepaymentService;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

function openPeriodRepaymentLoanAttributes(Member $member, array $overrides = []): array
{
    return array_merge([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'member_portion' => 12_000,
        'master_portion' => 12_000,
        'settlement_threshold' => 0,
        'installments_count' => 12,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ], $overrides);
}

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    Carbon::setTestNow(Carbon::parse('2026-02-06'));

    $this->accounting = app(AccountingService::class);
    $this->repayments = app(LoanRepaymentService::class);
    $this->cycles = app(ContributionCycleService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('open period repayment applies installment due mar 5 within february cycle', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();

    expect([$month, $year])->toBe([2, 2026]);

    $member = Member::create([
        'member_number' => 'OPEN-PER-1',
        'name' => 'February Cycle Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create(openPeriodRepaymentLoanAttributes($member));

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-03-05'),
        'status' => 'pending',
    ]);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($member->cashAccount, 2000, 'Deposit'),
    );

    expect($this->repayments->shouldOfferOpenPeriodRepayment($member->fresh()))->toBeTrue()
        ->and($this->repayments->applyOpenPeriodRepaymentForMember($member->fresh()))->toBe('applied')
        ->and(LoanInstallment::query()->where('loan_id', $loan->id)->value('status'))->toBe('paid');
});

test('open period repayment skips installment due mar 6 in march cycle', function () {
    $member = Member::create([
        'member_number' => 'OPEN-PER-1B',
        'name' => 'March Cycle Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create(openPeriodRepaymentLoanAttributes($member));

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-03-06'),
        'status' => 'pending',
    ]);

    expect($this->repayments->shouldOfferOpenPeriodRepayment($member))->toBeFalse()
        ->and($this->repayments->applyOpenPeriodRepaymentForMember($member))->toBe('skipped');
});

test('open period repayment applies february installment when business date is in february', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();

    $member = Member::create([
        'member_number' => 'OPEN-PER-2',
        'name' => 'February EMI Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create(openPeriodRepaymentLoanAttributes($member));

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-02-15'),
        'status' => 'pending',
    ]);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($member->cashAccount, 2000, 'Deposit'),
    );

    expect($this->repayments->shouldOfferOpenPeriodRepayment($member->fresh()))->toBeTrue()
        ->and($this->repayments->applyOpenPeriodRepaymentForMember($member->fresh()))->toBe('applied')
        ->and(LoanInstallment::query()->where('loan_id', $loan->id)->value('status'))->toBe('paid');
});

test('open period repayment applies when member is loan exempt despite posted contribution in same cycle', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-02'));

    [$month, $year] = $this->cycles->currentOpenPeriod();

    expect([$month, $year])->toBe([5, 2026]);

    $member = Member::create([
        'member_number' => 'OPEN-PER-3',
        'name' => 'May Cycle Borrower',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Contribution::factory()->for($member)->posted()->create([
        'period' => Contribution::periodDate($month, $year),
        'amount' => 500,
    ]);

    $loan = Loan::create(openPeriodRepaymentLoanAttributes($member));

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-06-05'),
        'status' => 'pending',
    ]);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($member->cashAccount, 2000, 'Deposit'),
    );

    expect($member->fresh()->isExemptFromContributions())->toBeTrue()
        ->and($this->repayments->shouldOfferOpenPeriodRepayment($member->fresh()))->toBeTrue()
        ->and($this->repayments->applyOpenPeriodRepaymentForMember($member->fresh()))->toBe('applied')
        ->and(LoanInstallment::query()->where('loan_id', $loan->id)->value('status'))->toBe('paid');
});
