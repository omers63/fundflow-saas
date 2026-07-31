<?php

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Support\ContributionAmountSettings;

beforeEach(function () {
    Setting::query()->where('group', ContributionAmountSettings::GROUP)->delete();
});

test('contribution amount steps default from 500 to 10500 by 500', function () {
    $steps = ContributionAmountSettings::steps();

    expect($steps)->toBe(range(500, 10_500, 500))
        ->and(Member::isValidContributionAmount(500))->toBeTrue()
        ->and(Member::isValidContributionAmount(10_500))->toBeTrue()
        ->and(Member::isValidContributionAmount(0))->toBeFalse()
        ->and(Member::isValidContributionAmount(750))->toBeFalse()
        ->and(Member::isValidContributionAmount(11_000))->toBeFalse();
});

test('dependent contribution amounts use the same steps and never allow zero', function () {
    expect(Member::isValidDependentContributionAmount(0))->toBeFalse()
        ->and(Member::isValidDependentContributionAmount(500))->toBeTrue()
        ->and(Member::isValidDependentContributionAmount(750))->toBeFalse()
        ->and(Member::dependentContributionAmountOptions())->not->toHaveKey(0)
        ->and(Member::dependentContributionAmountOptions())->toBe(Member::contributionAmountOptions());
});

test('contribution min, max and step are configurable via settings', function () {
    ContributionAmountSettings::saveFromForm([
        'contribution_amount_min' => 1000,
        'contribution_amount_step' => 1000,
        'contribution_amount_max' => 3000,
    ]);

    expect(ContributionAmountSettings::steps())->toBe([1000, 2000, 3000])
        ->and(Member::isValidContributionAmount(500))->toBeFalse()
        ->and(Member::isValidContributionAmount(1000))->toBeTrue()
        ->and(Member::isValidContributionAmount(1500))->toBeFalse()
        ->and(Member::isValidContributionAmount(3000))->toBeTrue()
        ->and(Member::isValidContributionAmount(4000))->toBeFalse();
});

test('member statuses are simplified to active inactive withdrawn', function () {
    expect(Member::STATUSES)->toBe(['active', 'inactive', 'withdrawn'])
        ->and(Member::PORTAL_BLOCKED_STATUSES)->toContain('inactive', 'withdrawn');
});
