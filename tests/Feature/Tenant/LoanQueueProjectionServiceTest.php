<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\Loans\LoanQueueProjectionService;
use App\Support\LoanQueueProjectionSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    Transaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $loanTier = LoanTier::query()->firstOrCreate(
        ['tier_number' => 0],
        ['label' => 'Loan Tier 0', 'min_amount' => 0, 'max_amount' => 50_000, 'min_monthly_installment' => 0, 'is_active' => true],
    );

    $this->fundTier = FundTier::query()->firstOrCreate(
        ['tier_number' => 1],
        ['label' => 'Fund Tier 1'],
    );
    $this->fundTier->update(['percentage' => 100, 'is_active' => true]);
    $loanTier->update(['fund_tier_id' => $this->fundTier->id]);
});

function makeProjectionMember(float $monthlyContribution): Member
{
    return Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Projection Member',
        'monthly_contribution_amount' => $monthlyContribution,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
}

function makeProjectionLoan(Member $member, FundTier $fundTier, array $overrides = []): Loan
{
    return Loan::query()->create(array_merge([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 0,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'approved',
        'is_emergency' => false,
        'applied_at' => now()->subDays(3),
        'approved_at' => now()->subDay(),
        'loan_tier_id' => $fundTier->loanTiers()->value('id'),
        'fund_tier_id' => $fundTier->id,
    ], $overrides));
}

test('queued loan fully covered by the tier pool is ready now', function () {
    Account::masterFund()->update(['balance' => 50_000]);
    $member = makeProjectionMember(0);
    $loan = makeProjectionLoan($member, $this->fundTier);

    $projection = app(LoanQueueProjectionService::class)->projectionFor($loan);

    expect($projection['ready_now'])->toBeTrue()
        ->and($projection['label'])->toBe(__('Ready now'));
});

test('projects months until expected contributions cover the shortfall', function () {
    makeProjectionMember(5000);
    $borrower = makeProjectionMember(0);
    $loan = makeProjectionLoan($borrower, $this->fundTier);

    $projection = app(LoanQueueProjectionService::class)->projectionFor($loan);

    expect($projection['ready_now'])->toBeFalse()
        ->and($projection['months_min'])->toBe(2)
        ->and($projection['months_max'])->toBe(2)
        ->and($projection['label'])->toBe(trans_choice('~:count month|~:count months', 2, ['count' => 2]));
});

test('falls back to historical master fund growth when no expected inflow exists', function () {
    $member = makeProjectionMember(0);
    $loan = makeProjectionLoan($member, $this->fundTier, [
        'amount_requested' => 4000,
        'amount_approved' => 4000,
    ]);

    Transaction::create([
        'account_id' => Account::masterFund()->id,
        'type' => 'credit',
        'amount' => 6000,
        'balance_after' => 6000,
        'description' => 'Historical inflow',
        'transacted_at' => now()->subMonth(),
    ]);

    $projection = app(LoanQueueProjectionService::class)->projectionFor($loan);

    expect($projection['ready_now'])->toBeFalse()
        ->and($projection['months_min'])->toBe(2)
        ->and($projection['months_max'])->toBe(2);
});

test('pending loans wait behind queued demand in the same tier', function () {
    makeProjectionMember(5000);
    $member = makeProjectionMember(0);
    $queued = makeProjectionLoan($member, $this->fundTier, [
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'queue_position' => 1,
    ]);
    $pending = makeProjectionLoan($member, $this->fundTier, [
        'status' => 'pending',
        'amount_requested' => 5000,
        'amount_approved' => null,
        'approved_at' => null,
        'fund_tier_id' => null,
    ]);

    $service = app(LoanQueueProjectionService::class);

    $queuedProjection = $service->projectionFor($queued);
    $pendingProjection = $service->projectionFor($pending);

    expect($queuedProjection['months_min'])->toBe(1)
        ->and($pendingProjection['months_min'])->toBe(2);
});

test('pending projection counts queued demand across tiers when configured', function () {
    $loanTierB = LoanTier::query()->firstOrCreate(
        ['tier_number' => 2],
        ['label' => 'Loan Tier 2', 'min_amount' => 0, 'max_amount' => 50_000, 'min_monthly_installment' => 0, 'is_active' => true],
    );
    $fundTierB = FundTier::query()->firstOrCreate(
        ['tier_number' => 2],
        ['label' => 'Fund Tier 2'],
    );
    $fundTierB->update(['percentage' => 100, 'is_active' => true]);
    $loanTierB->update(['fund_tier_id' => $fundTierB->id]);

    makeProjectionMember(5000);
    $member = makeProjectionMember(0);

    makeProjectionLoan($member, $this->fundTier, [
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'queue_position' => 1,
    ]);

    $pending = makeProjectionLoan($member, $fundTierB, [
        'status' => 'pending',
        'amount_requested' => 5000,
        'amount_approved' => null,
        'approved_at' => null,
        'fund_tier_id' => null,
        'loan_tier_id' => $loanTierB->id,
    ]);

    LoanQueueProjectionSettings::saveFromForm([
        'lqp_queued_demand_scope' => LoanQueueProjectionSettings::SCOPE_ACROSS_ALL_TIERS,
        'lqp_pending_demand_scope' => LoanQueueProjectionSettings::SCOPE_PENDING_WITHIN_TIER,
        'lqp_include_open_contributions' => true,
        'lqp_include_contribution_arrears' => false,
        'lqp_emi_forecast_months' => 3,
        'lqp_use_forward_inflow' => true,
        'lqp_use_historical_inflow' => false,
        'lqp_historical_lookback_months' => 3,
        'lqp_apply_tier_allocation' => true,
        'lqp_max_months_display' => 6,
    ]);

    $projection = app(LoanQueueProjectionService::class)->projectionFor($pending);

    expect($projection['months_min'])->toBe(2);
});
