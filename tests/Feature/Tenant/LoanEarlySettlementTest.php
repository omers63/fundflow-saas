<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanEarlySettlementService;
use App\Services\LoanService;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->accounting = app(AccountingService::class);
    $this->service = app(LoanService::class);
    $this->settlement = app(LoanEarlySettlementService::class);

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
});

function createSettlementEligibleMember(AccountingService $accounting, float $fundBalance = 15000): Member
{
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Test Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => $fundBalance]);
    $member->cashAccount()->update(['balance' => $fundBalance]);

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

function createActiveLoanForSettlement(LoanService $service, AccountingService $accounting, float $amount = 15000): Loan
{
    $member = createSettlementEligibleMember($accounting, 30000);
    Account::masterFund()->update(['balance' => 100000]);
    Account::masterCash()->update(['balance' => 100000]);

    $loan = $service->applyForLoan($member, $amount);
    $service->approveLoan($loan, $amount);
    $service->disburseLoan($loan);

    $loan = $loan->fresh(['member', 'installments']);
    $required = app(LoanEarlySettlementService::class)->requiredCash($loan);
    $loan->member->cashAccount()->update(['balance' => max($required, 50000)]);

    return $loan->fresh(['member', 'installments']);
}

test('unified settle with full amount marks loan early settled', function () {
    $loan = createActiveLoanForSettlement($this->service, $this->accounting);
    $required = $this->settlement->requiredCash($loan);

    $this->service->settleLoan($loan, $required);

    $loan->refresh();

    expect($loan->status)->toBe('early_settled')
        ->and($loan->settled_at)->not->toBeNull()
        ->and($loan->installments()->whereIn('status', ['pending', 'overdue'])->count())->toBe(0);
});

test('unified settle with partial amount keeps loan active and adjusts schedule', function () {
    $loan = createActiveLoanForSettlement($this->service, $this->accounting);
    $firstInstallment = $loan->installments()->orderBy('due_date')->first();
    $partialAmount = (float) $firstInstallment->amount;

    $this->service->settleLoan($loan, $partialAmount, 'roll_up');

    $loan->refresh();
    $firstInstallment->refresh();

    expect($loan->status)->toBe('active')
        ->and($firstInstallment->status)->toBe('paid');
});

test('settle rejects zero amount via partial path', function () {
    $loan = createActiveLoanForSettlement($this->service, $this->accounting);

    expect(fn () => $this->settlement->settle($loan, 0))
        ->toThrow(InvalidArgumentException::class);
});

test('settle rejects amount above member cash balance', function () {
    $loan = createActiveLoanForSettlement($this->service, $this->accounting);
    $loan->member->cashAccount()->update(['balance' => 1]);

    expect(fn () => $this->service->settleLoan($loan, 500))
        ->toThrow(RuntimeException::class);
});
