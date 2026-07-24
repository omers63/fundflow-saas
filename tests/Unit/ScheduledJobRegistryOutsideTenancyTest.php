<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Support\AutomationScheduleSettings;
use App\Support\ScheduledJobRegistry;

/**
 * TenantAwareScheduledCommand resolves ScheduledJobRegistry before entering tenant context.
 * Ending tenancy here mirrors that central bootstrap path without breaking RefreshDatabase teardown.
 */
it('builds the job registry without an active tenant context', function () {
    $tenant = tenant();
    assert($tenant instanceof Tenant);

    tenancy()->end();

    try {
        expect(AutomationScheduleSettings::monthBoundaryDay())->toBe(6)
            ->and(ScheduledJobRegistry::all())->not->toBeEmpty()
            ->and(ScheduledJobRegistry::find('fund:nightly-reconciliation'))->not->toBeNull()
            ->and(ScheduledJobRegistry::find('fund:reconcile --daily'))->not->toBeNull();
    } finally {
        tenancy()->initialize($tenant);
    }
});
