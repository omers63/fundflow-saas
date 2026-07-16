<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loan_tiers') || ! Schema::hasTable('fund_tiers')) {
            return;
        }

        if (! Schema::hasColumn('loan_tiers', 'fund_tier_id')) {
            Schema::table('loan_tiers', function (Blueprint $table): void {
                $table->foreignId('fund_tier_id')
                    ->nullable()
                    ->after('is_active')
                    ->constrained('fund_tiers')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasColumn('fund_tiers', 'loan_tier_id')) {
            // Prefer the lowest fund tier_number when multiple fund tiers pointed at one loan tier.
            $links = DB::table('fund_tiers')
                ->whereNotNull('loan_tier_id')
                ->orderBy('tier_number')
                ->orderBy('id')
                ->get(['id', 'loan_tier_id', 'tier_number']);

            $claimedLoanTier = [];

            foreach ($links as $link) {
                $loanTierId = (int) $link->loan_tier_id;

                if (isset($claimedLoanTier[$loanTierId])) {
                    continue;
                }

                $claimedLoanTier[$loanTierId] = (int) $link->id;

                DB::table('loan_tiers')
                    ->where('id', $loanTierId)
                    ->update(['fund_tier_id' => (int) $link->id]);
            }

            Schema::table('fund_tiers', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('loan_tier_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('loan_tiers') || ! Schema::hasTable('fund_tiers')) {
            return;
        }

        if (! Schema::hasColumn('fund_tiers', 'loan_tier_id')) {
            Schema::table('fund_tiers', function (Blueprint $table): void {
                $table->foreignId('loan_tier_id')
                    ->nullable()
                    ->after('label')
                    ->constrained('loan_tiers')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasColumn('loan_tiers', 'fund_tier_id')) {
            $links = DB::table('loan_tiers')
                ->whereNotNull('fund_tier_id')
                ->orderBy('tier_number')
                ->orderBy('id')
                ->get(['id', 'fund_tier_id']);

            $claimedFundTiers = [];

            foreach ($links as $link) {
                $fundTierId = (int) $link->fund_tier_id;

                if (isset($claimedFundTiers[$fundTierId])) {
                    continue;
                }

                $claimedFundTiers[$fundTierId] = true;

                DB::table('fund_tiers')
                    ->where('id', $fundTierId)
                    ->update(['loan_tier_id' => (int) $link->id]);
            }

            Schema::table('loan_tiers', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('fund_tier_id');
            });
        }
    }
};
