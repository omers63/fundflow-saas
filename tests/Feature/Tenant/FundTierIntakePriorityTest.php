<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanQueueOrderingService;
use App\Services\Loans\LoanQueueProjectionService;
use App\Services\Loans\LoanQueueService;
use App\Support\LoanQueueProjectionSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->member = Member::create([
        'member_number' => 'MEM-PRIO',
        'name' => 'Priority Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $bandLow = LoanTier::query()->firstOrCreate(
        ['tier_number' => 20],
        ['label' => 'Band Low', 'min_amount' => 0, 'max_amount' => 20_000, 'min_monthly_installment' => 500, 'is_active' => true],
    );
    $bandHigh = LoanTier::query()->firstOrCreate(
        ['tier_number' => 21],
        ['label' => 'Band High', 'min_amount' => 20_001, 'max_amount' => 40_000, 'min_monthly_installment' => 1000, 'is_active' => true],
    );

    $this->priorityOne = FundTier::query()->firstOrCreate(
        ['tier_number' => 21],
        ['label' => 'Priority One Pool'],
    );
    $this->priorityOne->update(['priority' => 1, 'percentage' => 50, 'is_active' => true]);
    $bandLow->update(['fund_tier_id' => $this->priorityOne->id]);

    $this->priorityTwo = FundTier::query()->firstOrCreate(
        ['tier_number' => 22],
        ['label' => 'Priority Two Pool'],
    );
    $this->priorityTwo->update(['priority' => 2, 'percentage' => 50, 'is_active' => true]);
    $bandHigh->update(['fund_tier_id' => $this->priorityTwo->id]);

    $this->bandLow = $bandLow->fresh();
    $this->bandHigh = $bandHigh->fresh();
});

function makePriorityPendingLoan(Member $member, LoanTier $loanTier, FundTier $fundTier, array $overrides = []): Loan
{
    return Loan::query()->create(array_merge([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => null,
        'amount_disbursed' => 0,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'pending',
        'is_emergency' => false,
        'applied_at' => now()->subDays(2),
        'loan_tier_id' => $loanTier->id,
        'fund_tier_id' => null,
    ], $overrides));
}

test('emergency fund tier priority is locked to zero on save', function () {
    $emergency = FundTier::query()->firstOrCreate(
        ['tier_number' => 0],
        ['label' => 'Emergency', 'percentage' => 100, 'is_active' => true],
    );

    $emergency->update(['priority' => 5, 'label' => 'Emergency']);

    expect((int) $emergency->fresh()->priority)->toBe(0);
});

test('intake queue ranks lower fund-tier priority ahead of earlier arrivals', function () {
    $earlierLowerPriority = makePriorityPendingLoan($this->member, $this->bandHigh, $this->priorityTwo, [
        'applied_at' => now()->subDays(10),
        'amount_requested' => 25_000,
        'amount' => 25_000,
    ]);
    $laterHigherPriority = makePriorityPendingLoan($this->member, $this->bandLow, $this->priorityOne, [
        'applied_at' => now()->subDays(1),
        'amount_requested' => 10_000,
        'amount' => 10_000,
    ]);

    $orderedIds = app(LoanQueueService::class)->intakeQuery()->pluck('id')->all();

    expect($orderedIds)->toBe([$laterHigherPriority->id, $earlierLowerPriority->id]);

    $ordered = LoanQueueOrderingService::orderIncomingPending(
        Loan::query()->with('loanTier')->where('status', 'pending')->get(),
    );

    expect($ordered->pluck('id')->all())->toBe([$laterHigherPriority->id, $earlierLowerPriority->id]);
});

test('projected wait counts higher-priority pending demand as ahead', function () {
    Setting::set(
        LoanQueueProjectionSettings::GROUP,
        'pending_demand_scope',
        LoanQueueProjectionSettings::SCOPE_PENDING_WITHIN_TIER,
    );
    Setting::set(LoanQueueProjectionSettings::GROUP, 'use_historical_inflow', false);
    Setting::set(LoanQueueProjectionSettings::GROUP, 'include_contribution_arrears', false);
    Setting::set(LoanQueueProjectionSettings::GROUP, 'apply_tier_allocation_percent', true);

    $priorityTwoLoan = makePriorityPendingLoan($this->member, $this->bandHigh, $this->priorityTwo, [
        'applied_at' => now()->subDays(5),
        'amount_requested' => 10_000,
        'amount' => 10_000,
    ]);

    $alone = app(LoanQueueProjectionService::class)->projectionFor($priorityTwoLoan->fresh());

    // Priority-1 backlog arrived later but ranks ahead of the priority-2 loan.
    makePriorityPendingLoan($this->member, $this->bandLow, $this->priorityOne, [
        'applied_at' => now()->subDay(),
        'amount_requested' => 10_000,
        'amount' => 10_000,
    ]);

    $withHigherPriorityAhead = app(LoanQueueProjectionService::class)->projectionFor($priorityTwoLoan->fresh());

    expect($alone['ready_now'])->toBeFalse()
        ->and($withHigherPriorityAhead['ready_now'])->toBeFalse()
        ->and($withHigherPriorityAhead['months_min'])->toBeGreaterThan($alone['months_min']);
});
