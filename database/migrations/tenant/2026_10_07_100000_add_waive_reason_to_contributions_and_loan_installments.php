<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why a row is waived: cutoff | admin_clearance | threshold | early_skip | fiscal_close.
     * Drives the unified payment status label (see LateSettledArrearsTableStyling).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('contributions', 'waive_reason')) {
            Schema::table('contributions', function (Blueprint $table): void {
                $table->string('waive_reason')->nullable()->after('is_late');
            });
        }

        if (! Schema::hasColumn('loan_installments', 'waive_reason')) {
            Schema::table('loan_installments', function (Blueprint $table): void {
                $table->string('waive_reason')->nullable()->after('waived_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contributions', 'waive_reason')) {
            Schema::table('contributions', function (Blueprint $table): void {
                $table->dropColumn('waive_reason');
            });
        }

        if (Schema::hasColumn('loan_installments', 'waive_reason')) {
            Schema::table('loan_installments', function (Blueprint $table): void {
                $table->dropColumn('waive_reason');
            });
        }
    }
};
