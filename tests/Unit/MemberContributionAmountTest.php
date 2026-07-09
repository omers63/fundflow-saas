<?php

use App\Models\Tenant\Member;

test('contribution amount steps are five-hundred increments up to three thousand', function () {
    expect(Member::CONTRIBUTION_STEPS)->toBe([500, 1000, 1500, 2000, 2500, 3000])
        ->and(Member::isValidContributionAmount(500))->toBeTrue()
        ->and(Member::isValidContributionAmount(1000))->toBeTrue()
        ->and(Member::isValidContributionAmount(3000))->toBeTrue()
        ->and(Member::isValidContributionAmount(0))->toBeFalse()
        ->and(Member::isValidContributionAmount(750))->toBeFalse()
        ->and(Member::isValidContributionAmount(3500))->toBeFalse();
});

test('dependent contribution amounts use the same steps and never allow zero', function () {
    expect(Member::isValidDependentContributionAmount(0))->toBeFalse()
        ->and(Member::isValidDependentContributionAmount(500))->toBeTrue()
        ->and(Member::isValidDependentContributionAmount(750))->toBeFalse()
        ->and(Member::dependentContributionAmountOptions())->not->toHaveKey(0)
        ->and(Member::dependentContributionAmountOptions())->toBe(Member::contributionAmountOptions());
});

test('member statuses are simplified to active inactive withdrawn', function () {
    expect(Member::STATUSES)->toBe(['active', 'inactive', 'withdrawn'])
        ->and(Member::PORTAL_BLOCKED_STATUSES)->toContain('inactive', 'withdrawn');
});
