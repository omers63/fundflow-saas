<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->timestamp('transacted_at');
            $table->foreignId('bank_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->foreignId('expense_disbursement_id')
                ->nullable()
                ->after('cash_out_request_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('expense_disbursement_id');
        });

        Schema::dropIfExists('expense_disbursements');
    }
};
