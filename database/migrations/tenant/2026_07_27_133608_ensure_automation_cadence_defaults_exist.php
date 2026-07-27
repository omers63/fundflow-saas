<?php

declare(strict_types=1);

use App\Support\AutomationScheduleSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill cron-like cadence keys (frequency/weekdays/month_days/times) for scoped
 * reconciliation/maintenance jobs on existing tenants.
 *
 * Tenants that already have legacy *_time values get *_times derived from them.
 * Fresh tenants receive the full schedule via {@see DefaultTenantSettings::seed()}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        AutomationScheduleSettings::seedDefaults(onlyMissing: true);
    }

    public function down(): void
    {
        // Defaults are configuration, not schema — leave rows in place on rollback.
    }
};
