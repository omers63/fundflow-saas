<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('master_bank_transaction_id')
                ->nullable()
                ->after('master_cash_transaction_id')
                ->constrained('transactions')
                ->nullOnDelete();

            $table->foreignId('master_fund_transaction_id')
                ->nullable()
                ->after('master_bank_transaction_id')
                ->constrained('transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('master_fund_transaction_id');
            $table->dropConstrainedForeignId('master_bank_transaction_id');
        });
    }
};
