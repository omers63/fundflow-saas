<?php

declare(strict_types=1);

use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\ContributionCycleService;
use App\Support\Insights\PoolFlowTrendBuilder;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('proportional height scales linearly against the shared max', function () {
    expect(PoolFlowTrendBuilder::proportionalHeight(50, 100))->toBe(50.0)
        ->and(PoolFlowTrendBuilder::proportionalHeight(100, 100))->toBe(100.0)
        ->and(PoolFlowTrendBuilder::proportionalHeight(25, 100))->toBe(25.0)
        ->and(PoolFlowTrendBuilder::proportionalHeight(0, 100))->toBe(0.0)
        ->and(PoolFlowTrendBuilder::proportionalHeight(10, 0))->toBe(0.0);
});

test('range exponential heights expand day-to-day deltas within a flat absolute band', function () {
    // Typical pool: large absolute level, modest day moves — absolute max scaling would look flat.
    $values = [9800.0, 9810.0, 9850.0, 9900.0, 10000.0];
    $heights = PoolFlowTrendBuilder::rangeExponentialHeights($values);

    expect($heights)->toHaveCount(5)
        ->and($heights[4])->toBe(100.0)
        ->and($heights[0])->toBe(18.0)
        ->and($heights[0])->toBeLessThan($heights[1])
        ->and($heights[1])->toBeLessThan($heights[2])
        ->and($heights[2])->toBeLessThan($heights[3])
        ->and($heights[3])->toBeLessThan($heights[4]);

    // Low-to-mid day-to-day step remains noticeable after range normalize + curve.
    $stepLow = $heights[1] - $heights[0];
    $stepHigh = $heights[4] - $heights[3];
    expect($stepLow)->toBeGreaterThan(1.0)
        ->and($stepHigh)->toBeGreaterThan(1.0);
});

test('range exponential heights stay mid-stub when the series is flat', function () {
    $heights = PoolFlowTrendBuilder::rangeExponentialHeights([5000.0, 5000.0, 5000.0]);

    expect($heights)->toBe([42.0, 42.0, 42.0]);
});

test('twelve cycle pool flow heights are proportional within each direction', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
    ]);

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $openPeriod = Contribution::periodDate($openMonth, $openYear);

    Contribution::factory()->for($member)->posted()->create([
        'period' => $openPeriod,
        'amount' => 2500,
        'posted_at' => $cycles->cycleStartAt($openMonth, $openYear)->addDay(),
    ]);

    Loan::factory()->for($member)->create([
        'status' => 'active',
        'amount' => 10_000,
        'amount_disbursed' => 10_000,
        'disbursed_at' => $cycles->cycleStartAt($openMonth, $openYear)->addDays(2),
    ]);

    $trend = PoolFlowTrendBuilder::twelveCycles($cycles);
    $openPoint = collect($trend['points'])->firstWhere('period', $openPeriod);

    // Separate max_in / max_out so a large loan does not crush inflow bars.
    expect($trend['max'])->toBe(10000.0)
        ->and($trend['max_in'])->toBe(2500.0)
        ->and($trend['max_out'])->toBe(10000.0)
        ->and($openPoint)->not->toBeNull()
        ->and($openPoint['out_heights']['loans'])->toBe(100.0)
        ->and($openPoint['in_heights']['contributions'])->toBe(100.0);
});

test('twelve cycle pool flow aggregates contributions and emi per cycle', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
    ]);

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $openPeriod = Contribution::periodDate($openMonth, $openYear);
    $previous = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();
    $previousPeriod = Contribution::periodDate((int) $previous->month, (int) $previous->year);

    Contribution::factory()->for($member)->posted()->create([
        'period' => $previousPeriod,
        'amount' => 800,
        'posted_at' => $cycles->cycleStartAt((int) $previous->month, (int) $previous->year)->addDay(),
    ]);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'active',
        'amount' => 10_000,
        'amount_disbursed' => 10_000,
        'disbursed_at' => $cycles->cycleStartAt($openMonth, $openYear)->addDays(2),
    ]);

    LoanRepayment::factory()->for($loan)->create([
        'amount' => 1200,
        'paid_at' => $cycles->cycleStartAt($openMonth, $openYear)->addDays(3),
    ]);

    CashOutRequest::query()->create([
        'member_id' => $member->id,
        'amount' => 300,
        'status' => 'accepted',
        'reviewed_at' => $cycles->cycleStartAt((int) $previous->month, (int) $previous->year)->addDays(4),
        'reviewed_by' => User::query()->create([
            'name' => 'Reviewer',
            'email' => 'reviewer-pool-flow@test.com',
            'password' => bcrypt('password'),
            'is_admin' => true,
        ])->id,
    ]);

    $trend = PoolFlowTrendBuilder::twelveCycles($cycles);

    expect($trend['points'])->toHaveCount(12)
        ->and($trend['inflow_series'])->toHaveCount(2)
        ->and($trend['outflow_series'])->toHaveCount(3)
        ->and($trend['lines']['inflow'])->toHaveKeys(['contributions', 'emi'])
        ->and($trend['lines']['outflow'])->toHaveKeys(['loans', 'cash_outs', 'reserves']);

    $previousPoint = collect($trend['points'])->firstWhere('period', $previousPeriod);
    $openPoint = collect($trend['points'])->firstWhere('period', $openPeriod);

    expect($previousPoint)->not->toBeNull()
        ->and($previousPoint['in']['contributions'])->toBe(800.0)
        ->and($previousPoint['out']['cash_outs'])->toBe(300.0)
        ->and($openPoint)->not->toBeNull()
        ->and($openPoint['in']['emi'])->toBe(1200.0)
        ->and($openPoint['out']['loans'])->toBe(10000.0);
});
