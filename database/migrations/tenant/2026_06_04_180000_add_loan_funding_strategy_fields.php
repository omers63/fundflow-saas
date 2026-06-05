<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('funding_strategy', 32)
                ->default('member_fund_topup')
                ->after('is_emergency');
            $table->boolean('cash_out_excess_fund')
                ->default(false)
                ->after('funding_strategy');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['funding_strategy', 'cash_out_excess_fund']);
        });
    }
};
