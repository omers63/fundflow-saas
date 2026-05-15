<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
            $table->date('transaction_date');
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable();
            $table->string('transaction_type')->nullable();
            $table->enum('status', ['imported', 'mirrored', 'posted', 'ignored'])->default('imported');
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hash')->unique();
            $table->text('raw_data')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('transaction_date');
            $table->index(['bank_statement_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
