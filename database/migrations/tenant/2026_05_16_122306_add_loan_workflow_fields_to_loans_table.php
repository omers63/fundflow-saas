<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->text('purpose')->nullable()->after('amount');
            $table->text('rejection_reason')->nullable()->after('status');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->timestamp('cancelled_at')->nullable()->after('rejected_at');
        });

        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('paid_at');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE loans MODIFY COLUMN status ENUM(
                'pending',
                'approved',
                'rejected',
                'cancelled',
                'disbursed',
                'repaying',
                'completed',
                'defaulted'
            ) NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'rejection_reason', 'rejected_at', 'cancelled_at']);
        });

        if (Schema::hasTable('loan_repayments')) {
            Schema::table('loan_repayments', function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE loans MODIFY COLUMN status ENUM(
                'pending',
                'approved',
                'disbursed',
                'repaying',
                'completed',
                'defaulted'
            ) NOT NULL DEFAULT 'pending'");
        }
    }
};
