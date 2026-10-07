<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How an installment was settled early: early_full | early_partial (null otherwise).
     * Drives the "Early settlement" chip (see LateSettledArrearsTableStyling::paymentChips).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('loan_installments', 'settled_via')) {
            Schema::table('loan_installments', function (Blueprint $table): void {
                $table->string('settled_via')->nullable()->after('waive_reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('loan_installments', 'settled_via')) {
            Schema::table('loan_installments', function (Blueprint $table): void {
                $table->dropColumn('settled_via');
            });
        }
    }
};
