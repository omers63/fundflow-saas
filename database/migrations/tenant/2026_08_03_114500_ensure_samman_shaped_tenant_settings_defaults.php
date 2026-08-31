<?php

declare(strict_types=1);

use App\Support\DefaultTenantSettings;
use Database\Seeders\Tenant\TenantDatabaseSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Install or backfill tenant Settings defaults (Samman production policy shape).
 *
 * - Empty settings table → full {@see DefaultTenantSettings::seed()}
 * - Existing tenants → missing keys only (never overwrites live values)
 *
 * Also invoked from {@see TenantDatabaseSeeder} on new installs.
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
