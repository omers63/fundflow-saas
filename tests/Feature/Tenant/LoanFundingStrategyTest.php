<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanLifecycleService;
use App\Services\LoanService;
use App\Support\ContributionCollectionStatus;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 200_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 200_000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->lifecycle = app(LoanLifecycleService::class);
    $this->loanService = app(LoanService::class);
});

function createEligibleMemberForFundingTest(AccountingService $accounting, float $fundBalance = 20_000): Member
{
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Funding Test Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => $fundBalance]);
    $member->cashAccount()->update(['balance' => 0]);

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $cursor = $member->joined_at->copy()->startOfMonth();

    while ($cursor->lte(Carbon::create($openYear, $openMonth, 1)->endOfMonth())) {
        $month = (int) $cursor->month;
        $year = (int) $cursor->year;

        if ((float) $member->monthly_contribution_amount > 0 && ! $member->isExemptFromContributions($month, $year)) {
            Contribution::create([
                'member_id' => $member->id,
                'period' => Contribution::periodDate($month, $year),
                'amount' => $member->monthly_contribution_amount,
                'amount_due' => $member->monthly_contribution_amount,
                'amount_collected' => $member->monthly_contribution_amount,
                'status' => 'posted',
                'collection_status' => ContributionCollectionStatus::COLLECTED,
                'posted_at' => $cursor->copy()->endOfMonth(),
                'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                'is_late' => false,
            ]);
        }

        $cursor->addMonthNoOverflow();
    }

    return $member->fresh()->load(['fundAccount', 'cashAccount']);
}

test('loan application stores per-loan funding strategy and cash-out preference', function () {
    $member = createEligibleMemberForFundingTest($this->accounting);

    $loan = $this->lifecycle->applyForLoan(
        $member,
        10_000,
        'Education',
        fundingStrategy: LoanFundingStrategy::SPLIT_PERCENTAGE,
        cashOutExcessFund: true,
    );

    expect($loan->funding_strategy)->toBe(LoanFundingStrategy::SPLIT_PERCENTAGE)
        ->and($loan->cash_out_excess_fund)->toBeTrue();
});

test('member fund top-up strategy clears cash-out flag on apply', function () {
    $member = createEligibleMemberForFundingTest($this->accounting);

    $loan = $this->lifecycle->applyForLoan(
        $member,
        10_000,
        'Education',
        fundingStrategy: LoanFundingStrategy::MEMBER_FUND_TOPUP,
        cashOutExcessFund: true,
    );

    expect($loan->funding_strategy)->toBe(LoanFundingStrategy::MEMBER_FUND_TOPUP)
        ->and($loan->cash_out_excess_fund)->toBeFalse();
});

test('split strategy with cash-out moves excess fund to cash at full disbursement', function () {
    LoanSettings::save(['member_funding_split_pct' => 50]);

    $member = createEligibleMemberForFundingTest($this->accounting, 15_000);
    $fundBefore = 15_000.0;
    $cashBefore = 0.0;
    $excess = LoanSettings::excessFundCashOutAmount(10_000, $fundBefore, LoanFundingStrategy::SPLIT_PERCENTAGE);

    expect($excess)->toBe(10_000.0);

    $loan = $this->lifecycle->applyForLoan(
        $member,
        10_000,
        'Split loan',
        fundingStrategy: LoanFundingStrategy::SPLIT_PERCENTAGE,
        cashOutExcessFund: true,
    );
    $this->loanService->approveLoan($loan, 10_000);
    $this->loanService->disburseLoan($loan);

    $member->refresh();

    expect($loan->fresh()->status)->toBe('active')
        ->and((float) $member->fundAccount->balance)->toBe(-5000.0)
        ->and((float) $member->cashAccount->balance)->toBe($cashBefore + $excess + 10_000.0)
        ->and((float) $loan->member_portion)->toBe(5000.0)
        ->and((float) $loan->master_portion)->toBe(5000.0);
});

test('split strategy debits member fund for master portion after excess cash-out', function () {
    LoanSettings::save(['member_funding_split_pct' => 50]);

    $member = createEligibleMemberForFundingTest($this->accounting, 53_000);

    $loan = $this->lifecycle->applyForLoan(
        $member,
        80_000,
        'Split 80k',
        fundingStrategy: LoanFundingStrategy::SPLIT_PERCENTAGE,
        cashOutExcessFund: true,
    );
    $this->loanService->approveLoan($loan, 80_000);
    $this->loanService->disburseLoan($loan);

    $member->unsetRelation('fundAccount');
    $member->unsetRelation('cashAccount');
    $member->refresh();

    expect($loan->fresh()->status)->toBe('active')
        ->and((float) $loan->member_portion)->toBe(40_000.0)
        ->and((float) $loan->master_portion)->toBe(40_000.0)
        ->and($member->getFundBalance())->toBe(-40_000.0)
        ->and($member->getCashBalance())->toBe(93_000.0);
});

test('member fund top-up uses available fund balance for member portion', function () {
    $member = createEligibleMemberForFundingTest($this->accounting, 12_000);

    $loan = $this->lifecycle->applyForLoan(
        $member,
        10_000,
        'Top-up loan',
        fundingStrategy: LoanFundingStrategy::MEMBER_FUND_TOPUP,
    );
    $this->loanService->approveLoan($loan, 10_000);
    $this->loanService->disburseLoan($loan);

    $loan->refresh();
    $member->unsetRelation('fundAccount');
    $member->unsetRelation('cashAccount');
    $member->refresh();

    expect((float) $loan->member_portion)->toBe(10_000.0)
        ->and((float) $loan->master_portion)->toBe(0.0)
        ->and($member->getFundBalance())->toBe(2000.0)
        ->and($member->getCashBalance())->toBe(10_000.0);
});
