<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->timestamp('transacted_at');
            $table->timestamps();
        });

        Schema::create('fee_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->timestamp('transacted_at');
            $table->foreignId('bank_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->foreignId('fee_disbursement_id')
                ->nullable()
                ->after('expense_disbursement_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fee_disbursement_id');
        });

        Schema::dropIfExists('fee_disbursements');
        Schema::dropIfExists('fee_deductions');
    }
};
