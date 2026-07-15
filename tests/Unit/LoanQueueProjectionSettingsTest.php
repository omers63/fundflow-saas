<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Support\LoanQueueProjectionSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->where('group', LoanQueueProjectionSettings::GROUP)->delete();
});

test('projection settings persist from form state', function () {
    LoanQueueProjectionSettings::saveFromForm([
        'lqp_queued_demand_scope' => LoanQueueProjectionSettings::SCOPE_ACROSS_ALL_TIERS,
        'lqp_pending_demand_scope' => LoanQueueProjectionSettings::SCOPE_PENDING_ACROSS_ALL,
        'lqp_include_open_contributions' => false,
        'lqp_include_contribution_arrears' => true,
        'lqp_emi_forecast_months' => 6,
        'lqp_use_forward_inflow' => true,
        'lqp_use_historical_inflow' => false,
        'lqp_historical_lookback_months' => 12,
        'lqp_apply_tier_allocation' => false,
        'lqp_max_months_display' => 9,
    ]);

    expect(LoanQueueProjectionSettings::queuedDemandScope())->toBe(LoanQueueProjectionSettings::SCOPE_ACROSS_ALL_TIERS)
        ->and(LoanQueueProjectionSettings::pendingDemandScope())->toBe(LoanQueueProjectionSettings::SCOPE_PENDING_ACROSS_ALL)
        ->and(LoanQueueProjectionSettings::includeOpenPeriodContributions())->toBeFalse()
        ->and(LoanQueueProjectionSettings::includeContributionArrears())->toBeTrue()
        ->and(LoanQueueProjectionSettings::emiForecastMonths())->toBe(6)
        ->and(LoanQueueProjectionSettings::useHistoricalInflow())->toBeFalse()
        ->and(LoanQueueProjectionSettings::historicalLookbackMonths())->toBe(12)
        ->and(LoanQueueProjectionSettings::applyTierAllocationPercent())->toBeFalse()
        ->and(LoanQueueProjectionSettings::maxMonthsDisplay())->toBe(9);
});

test('projection settings fall back to defaults for invalid scope values', function () {
    LoanQueueProjectionSettings::saveFromForm([
        'lqp_queued_demand_scope' => 'invalid',
        'lqp_pending_demand_scope' => 'invalid',
        'lqp_emi_forecast_months' => 99,
        'lqp_historical_lookback_months' => 0,
        'lqp_max_months_display' => 0,
    ]);

    expect(LoanQueueProjectionSettings::queuedDemandScope())->toBe(LoanQueueProjectionSettings::SCOPE_WITHIN_TIER)
        ->and(LoanQueueProjectionSettings::pendingDemandScope())->toBe(LoanQueueProjectionSettings::SCOPE_PENDING_WITHIN_TIER)
        ->and(LoanQueueProjectionSettings::emiForecastMonths())->toBe(24)
        ->and(LoanQueueProjectionSettings::historicalLookbackMonths())->toBe(1)
        ->and(LoanQueueProjectionSettings::maxMonthsDisplay())->toBe(1);
});
