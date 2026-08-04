<?php

declare(strict_types=1);

use App\Support\DefaultFundAndLoanTiers;
use App\Support\DefaultTenantSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure Settings catalog data (fund/loan tiers, bank import templates, notification
 * templates) and any missing settings keys exist after migrate.
 *
 * Percentages / priorities for fund tiers follow Samman production
 * ({@see DefaultFundAndLoanTiers}). Existing tier rows are never overwritten.
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
        // Catalog defaults are intentional product data; do not drop on rollback.
    }
};
