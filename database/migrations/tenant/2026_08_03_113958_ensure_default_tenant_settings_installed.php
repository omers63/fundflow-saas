<?php

declare(strict_types=1);

use App\Support\DefaultTenantSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Fresh tenant DBs that only run migrations (before seeder) still get Settings defaults.
 * Existing tenants only receive missing keys — never overwrite live values.
 *
 * Full shapes come from {@see DefaultTenantSettings::seed()} (also called by TenantDatabaseSeeder).
 * Defaults match the live Samman production policy shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DefaultTenantSettings::ensureInstalled();
    }

    public function down(): void
    {
        // Settings rows are intentional product defaults; do not drop on rollback.
    }
};
