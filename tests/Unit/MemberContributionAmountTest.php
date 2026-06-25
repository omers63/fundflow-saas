<?php

use App\Models\Tenant\Member;

test('contribution amount steps are five-hundred increments up to three thousand', function () {
    expect(Member::CONTRIBUTION_STEPS)->toBe([500, 1000, 1500, 2000, 2500, 3000])
        ->and(Member::isValidContributionAmount(500))->toBeTrue()
        ->and(Member::isValidContributionAmount(1000))->toBeTrue()
        ->and(Member::isValidContributionAmount(3000))->toBeTrue()
        ->and(Member::isValidContributionAmount(750))->toBeFalse()
        ->and(Member::isValidContributionAmount(3500))->toBeFalse();
});

test('member statuses include inactive delinquent and terminated', function () {
    expect(Member::STATUSES)->toContain('inactive', 'delinquent', 'terminated')
        ->and(Member::PORTAL_BLOCKED_STATUSES)->toContain('inactive', 'delinquent', 'terminated');
});
