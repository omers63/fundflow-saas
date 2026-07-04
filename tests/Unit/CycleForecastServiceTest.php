<?php

use App\Services\ContributionCycleService;
use App\Services\CycleForecastService;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('cycle forecast projects close percentage from elapsed pace', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();

    $forecast = app(CycleForecastService::class)->project(
        $month,
        $year,
        5,
        10,
        5_000.0,
        10_000.0,
    );

    expect($forecast['projected_close_percent'])->toBeGreaterThan(0)
        ->and($forecast['remaining_count'])->toBe(5)
        ->and($forecast['remaining_amount'])->toBe(5_000.0)
        ->and($forecast['days_remaining'])->toBeGreaterThanOrEqual(0)
        ->and($forecast['tone'])->toBeString();

    Carbon::setTestNow();
});

test('cycle forecast returns success tone when fully collected', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();

    $forecast = app(CycleForecastService::class)->project(
        $month,
        $year,
        10,
        10,
        10_000.0,
        10_000.0,
    );

    expect($forecast['projected_close_percent'])->toBe(100)
        ->and($forecast['remaining_count'])->toBe(0)
        ->and($forecast['tone'])->toBe('success');

    Carbon::setTestNow();
});
