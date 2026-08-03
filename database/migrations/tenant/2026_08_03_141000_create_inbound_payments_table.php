<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_payments', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payer_name');
            $table->decimal('amount', 15, 2);
            $table->text('reason');
            $table->date('instruction_date');
            $table->string('status', 20)->default('pending');
            $table->foreignId('bank_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payer_iban', 34)->nullable();
            $table->string('payer_bank_account_number', 50)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('check_number', 50)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('completion_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->unique('bank_transaction_id');
            $table->index('status');
            $table->index('type');
            $table->index('instruction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_payments');
    }
};
