<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanQueueService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    FundTier::query()->forceDelete();

    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 50_000, 'is_master' => true]);

    $this->member = Member::create([
        'member_number' => 'MEM-LQS',
        'name' => 'Queue Service Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $loanTierA = LoanTier::query()->firstOrCreate(
        ['tier_number' => 1],
        ['label' => 'Loan Tier 1', 'min_amount' => 0, 'max_amount' => 20_000, 'min_monthly_installment' => 0, 'is_active' => true],
    );
    $loanTierB = LoanTier::query()->firstOrCreate(
        ['tier_number' => 2],
        ['label' => 'Loan Tier 2', 'min_amount' => 20_001, 'max_amount' => 50_000, 'min_monthly_installment' => 0, 'is_active' => true],
    );

    $this->fundTierA = FundTier::create([
        'tier_number' => 1,
        'label' => 'Fund Tier A',
        'loan_tier_id' => $loanTierA->id,
        'percentage' => 100,
        'is_active' => true,
    ]);
    $this->fundTierB = FundTier::create([
        'tier_number' => 2,
        'label' => 'Fund Tier B',
        'loan_tier_id' => $loanTierB->id,
        'percentage' => 100,
        'is_active' => true,
    ]);

    $this->queue = app(LoanQueueService::class);
});

test('disbursable kpi uses master fund not summed overlapping tier headrooms', function () {
    expect($this->fundTierA->fresh()->disbursable_pool)->toBe(50_000.0)
        ->and($this->fundTierB->fresh()->disbursable_pool)->toBe(50_000.0)
        ->and($this->queue->kpis()['disbursable'])->toBe(50_000.0);
});

test('process coverage shares one master fund pool across overlapping tiers', function () {
    Loan::query()->create([
        'member_id' => $this->member->id,
        'amount' => 30_000,
        'amount_requested' => 30_000,
        'amount_approved' => 30_000,
        'amount_disbursed' => 0,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 2500,
        'total_repaid' => 0,
        'status' => 'approved',
        'applied_at' => now()->subDays(3),
        'approved_at' => now()->subDay(),
        'loan_tier_id' => $this->fundTierA->loan_tier_id,
        'fund_tier_id' => $this->fundTierA->id,
        'queue_position' => 1,
    ]);

    $memberB = Member::create([
        'member_number' => 'MEM-LQS2',
        'name' => 'Second Borrower',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Loan::query()->create([
        'member_id' => $memberB->id,
        'amount' => 30_000,
        'amount_requested' => 30_000,
        'amount_approved' => 30_000,
        'amount_disbursed' => 0,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 2500,
        'total_repaid' => 0,
        'status' => 'approved',
        'applied_at' => now()->subDays(2),
        'approved_at' => now()->subDay(),
        'loan_tier_id' => $this->fundTierB->loan_tier_id,
        'fund_tier_id' => $this->fundTierB->id,
        'queue_position' => 1,
    ]);

    $coverage = app(LoanQueueService::class)->processCoverage();
    $payable = array_sum(array_map(fn (array $row): float => $row['amount'], $coverage));

    expect($payable)->toBe(50_000.0)
        ->and($coverage)->toHaveCount(2)
        ->and($coverage[array_key_first($coverage)]['amount'])->toBe(30_000.0)
        ->and($coverage[array_key_last($coverage)]['amount'])->toBe(20_000.0);
});

test('process query lists all loans awaiting disbursement even when tier headroom is exhausted', function () {
    Loan::query()->create([
        'member_id' => $this->member->id,
        'amount' => 200_000,
        'amount_requested' => 200_000,
        'amount_approved' => 200_000,
        'amount_disbursed' => 200_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 16_667,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonths(3),
        'approved_at' => now()->subMonths(3),
        'disbursed_at' => now()->subMonths(3),
        'loan_tier_id' => $this->fundTierA->loan_tier_id,
        'fund_tier_id' => $this->fundTierA->id,
    ]);

    $waiting = Loan::query()->create([
        'member_id' => $this->member->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 6_600,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1_667,
        'total_repaid' => 0,
        'status' => 'partially_disbursed',
        'applied_at' => now()->subDays(2),
        'approved_at' => now()->subDay(),
        'loan_tier_id' => $this->fundTierA->loan_tier_id,
        'fund_tier_id' => $this->fundTierA->id,
        'queue_position' => 1,
    ]);

    $queue = app(LoanQueueService::class);

    expect($queue->processQuery()->pluck('id')->all())->toBe([(int) $waiting->id])
        ->and($queue->processCoverage())->toBe([])
        ->and($queue->kpis()['queued_demand'])->toBe(13_400.0)
        ->and($queue->kpis()['process'])->toBe(0);
});
