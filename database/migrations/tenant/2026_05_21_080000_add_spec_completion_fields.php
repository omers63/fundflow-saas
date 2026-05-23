<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_exceptions', function (Blueprint $table) {
            if (! Schema::hasColumn('reconciliation_exceptions', 'exception_type')) {
                $table->string('exception_type', 32)->nullable()->after('exception_code');
            }
            if (! Schema::hasColumn('reconciliation_exceptions', 'deferred_until')) {
                $table->timestamp('deferred_until')->nullable()->after('sla_deadline');
            }
        });

        Schema::table('members', function (Blueprint $table) {
            if (! Schema::hasColumn('members', 'opening_cash_balance')) {
                $table->decimal('opening_cash_balance', 14, 2)->nullable()->after('migration_status');
            }
            if (! Schema::hasColumn('members', 'opening_fund_balance')) {
                $table->decimal('opening_fund_balance', 14, 2)->nullable()->after('opening_cash_balance');
            }
            if (! Schema::hasColumn('members', 'opening_balances_posted_at')) {
                $table->timestamp('opening_balances_posted_at')->nullable()->after('opening_fund_balance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn([
                'opening_cash_balance',
                'opening_fund_balance',
                'opening_balances_posted_at',
            ]);
        });

        Schema::table('reconciliation_exceptions', function (Blueprint $table) {
            $table->dropColumn(['exception_type', 'deferred_until']);
        });
    }
};
