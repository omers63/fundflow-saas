<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_tiers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('priority')->default(0)->after('tier_number');
        });

        // Lower number = higher intake rank. Seed from tier_number so emergency stays 0.
        DB::table('fund_tiers')->update([
            'priority' => DB::raw('tier_number'),
        ]);
    }

    public function down(): void
    {
        Schema::table('fund_tiers', function (Blueprint $table): void {
            $table->dropColumn('priority');
        });
    }
};
