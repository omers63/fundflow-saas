<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            if (! Schema::hasColumn('loans', 'excess_fund_settlement_option')) {
                $table->string('excess_fund_settlement_option', 32)
                    ->nullable()
                    ->after('cash_out_excess_fund');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            if (Schema::hasColumn('loans', 'excess_fund_settlement_option')) {
                $table->dropColumn('excess_fund_settlement_option');
            }
        });
    }
};
