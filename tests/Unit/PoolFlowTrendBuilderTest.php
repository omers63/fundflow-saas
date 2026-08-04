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

test('exponential height expands relative differences super-linearly toward the max', function () {
    $half = PoolFlowTrendBuilder::exponentialHeight(50, 100);
    $full = PoolFlowTrendBuilder::exponentialHeight(100, 100);
    $quarter = PoolFlowTrendBuilder::exponentialHeight(25, 100);

    expect($full)->toBe(100.0)
        ->and($half)->toBeLessThan(50.0)
        ->and($half)->toBeGreaterThan($quarter)
        ->and(PoolFlowTrendBuilder::exponentialHeight(0, 100))->toBe(0.0);
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
