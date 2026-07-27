<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Support\AutomationScheduleSettings;
use App\Support\DefaultTenantSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill automation schedule defaults on existing tenants (and no-op when already set).
 * Fresh tenants also receive these via {@see DefaultTenantSettings::seed()}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        if (blank(Setting::get('contribution', 'cycle_start_day'))) {
            Setting::set('contribution', 'cycle_start_day', (string) DefaultTenantSettings::CYCLE_START_DAY);
        }

        AutomationScheduleSettings::seedDefaults(onlyMissing: true);
    }

    public function down(): void
    {
        // Defaults are configuration, not schema — leave rows in place on rollback.
    }
};
