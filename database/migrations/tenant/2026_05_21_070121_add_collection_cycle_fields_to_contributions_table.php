<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->string('collection_status', 32)->nullable()->after('status');
            $table->decimal('amount_due', 12, 2)->nullable()->after('amount');
            $table->decimal('amount_collected', 12, 2)->default(0)->after('amount_due');
            $table->timestamp('overdue_since')->nullable()->after('paid_at');
            $table->unsignedTinyInteger('late_fee_tier')->nullable()->after('late_fee_amount');
            $table->decimal('cycle_open_cash_balance', 12, 2)->nullable()->after('late_fee_tier');
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropColumn([
                'collection_status',
                'amount_due',
                'amount_collected',
                'overdue_since',
                'late_fee_tier',
                'cycle_open_cash_balance',
            ]);
        });
    }
};
