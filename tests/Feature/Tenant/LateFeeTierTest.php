<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Services\Loans\LateFeeService;
use App\Support\ContributionCollectionStatus;
use App\Support\ContributionPolicySettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('contribution late fee uses tier 4 amount when overdue at least 30 days', function () {
    ContributionPolicySettings::saveFromForm([
        'delinquency_consecutive' => 3,
        'delinquency_total' => 15,
        'delinquency_lookback_months' => 60,
        'late_fee_contribution_1d' => 25,
        'late_fee_contribution_10d' => 50,
        'late_fee_contribution_20d' => 100,
        'late_fee_contribution_30d' => 150,
        'late_fee_repayment_1d' => 0,
        'late_fee_repayment_10d' => 0,
        'late_fee_repayment_20d' => 0,
        'late_fee_repayment_30d' => 0,
        'annual_subscription_fee' => 0,
    ]);

    $service = app(LateFeeService::class);

    expect(ContributionCollectionStatus::tierForDays(35))->toBe(4)
        ->and($service->contributionLateFeeForDays(35))->toBe(150.0)
        ->and(ContributionCollectionStatus::tierForDays(25))->toBe(3)
        ->and($service->contributionLateFeeForDays(25))->toBe(100.0)
        ->and((float) Setting::get('late_fee', 'contribution_day_30'))->toBe(150.0);
});
