<?php

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Services\ContributionInsightsService;
use App\Support\Insights\DualProgressTrendBuilder;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    Contribution::query()->delete();
    Member::query()->delete();
});

test('insights snapshot aggregates contribution pipeline metrics', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
    ]);

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
    $period = Contribution::periodDate($month, $year);

    Contribution::factory()->for($member)->create([
        'period' => $period,
        'amount' => 1000,
        'status' => 'pending',
        'is_late' => true,
    ]);

    Contribution::factory()->for($member)->posted()->create([
        'period' => now()->subMonths(3)->startOfMonth()->toDateString(),
        'amount' => 1000,
    ]);

    Contribution::factory()->for($member)->failed()->create([
        'period' => now()->subMonths(2)->startOfMonth()->toDateString(),
        'amount' => 500,
    ]);

    $snapshot = app(ContributionInsightsService::class)->snapshot();

    expect($snapshot['pending'])->toBe(1)
        ->and($snapshot['posted'])->toBe(1)
        ->and($snapshot['failed'])->toBe(1)
        ->and($snapshot['late_count'])->toBe(1)
        ->and($snapshot['open_period']['label'])->not->toBeEmpty()
        ->and($snapshot['pipeline'])->toHaveKeys([
            'contributions_pending_url',
            'cycle_url',
            'arrears_url',
        ])
        ->and($snapshot['open_period']['collection_rate'])->toBeInt();
});

test('collect snapshot exposes cycle collection amount stats', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
    ]);

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

    Contribution::factory()->for($member)->posted()->create([
        'period' => Contribution::periodDate($month, $year),
        'amount' => 1500,
    ]);

    $snapshot = app(ContributionInsightsService::class)->forContext('collect');

    expect($snapshot)->toHaveKeys(['collection_amounts'])
        ->and($snapshot['collection_amounts']['recovered_amount'])->toBe(1500.0)
        ->and($snapshot['collection_amounts']['unrecovered_amount'])->toBe(0.0);
});

test('collected snapshot amount kpi exposes numeric value for stat rendering', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
    ]);

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
    $period = Contribution::periodDate($month, $year);

    Contribution::factory()->for($member)->posted()->create([
        'period' => $period,
        'amount' => 2500,
    ]);

    $snapshot = app(ContributionInsightsService::class)->forContext('collected');
    $amountKpi = collect($snapshot['kpis'])->firstWhere('key', 'amount');

    expect($amountKpi)->not->toBeNull()
        ->and($amountKpi['value'])->toBe(2500.0)
        ->and($amountKpi['currency'])->toBeString();
});

test('six month trend buckets contributions using normalized period keys', function () {
    $member = Member::factory()->create();

    $twoMonthsAgo = now()->subMonths(2)->startOfMonth();
    $postedPeriod = Contribution::periodDate((int) $twoMonthsAgo->month, (int) $twoMonthsAgo->year);
    $pendingMonth = now()->subMonths(3)->startOfMonth();
    $pendingPeriod = Contribution::periodDate((int) $pendingMonth->month, (int) $pendingMonth->year);

    Contribution::factory()->for($member)->posted()->create([
        'period' => $postedPeriod,
        'amount' => 750,
    ]);

    Contribution::factory()->for($member)->create([
        'period' => $pendingPeriod,
        'amount' => 500,
        'status' => 'pending',
    ]);

    $trend = collect(DualProgressTrendBuilder::sixMonthFundCollectionTrend(app(ContributionCycleService::class)));
    $postedBucket = $trend->firstWhere('label', $twoMonthsAgo->locale(app()->getLocale())->translatedFormat('M'));
    $pendingBucket = $trend->firstWhere('label', $pendingMonth->locale(app()->getLocale())->translatedFormat('M'));

    expect($postedBucket)->not->toBeNull()
        ->and($postedBucket['posted'])->toBe(1)
        ->and($postedBucket['posted_amount'])->toBe(750.0)
        ->and($pendingBucket)->not->toBeNull()
        ->and($pendingBucket['posted'])->toBe(0)
        ->and($pendingBucket['posted_amount'])->toBe(0.0);
});

test('six month trend expresses bars relative to expected collections', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
    ]);

    Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
    ]);

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
    $period = Contribution::periodDate($month, $year);

    Contribution::factory()->for($member)->posted()->create([
        'period' => $period,
        'amount' => 1000,
    ]);

    $bucket = collect(DualProgressTrendBuilder::sixMonthFundCollectionTrend(app(ContributionCycleService::class)))
        ->firstWhere('label', Carbon::create($year, $month, 1)->locale(app()->getLocale())->translatedFormat('M'));

    expect($bucket)->not->toBeNull()
        ->and($bucket['posted'])->toBe(1)
        ->and($bucket['expected_count'])->toBe(2)
        ->and($bucket['expected_amount'])->toBe(1500.0)
        ->and($bucket['collection_rate'])->toBe(50)
        ->and($bucket['amount_collection_rate'])->toBe(67)
        ->and($bucket['tone'])->toBe('warning')
        ->and($bucket['subtitle'])->toContain('1/2');
});
