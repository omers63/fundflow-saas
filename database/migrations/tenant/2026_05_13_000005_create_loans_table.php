<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->unsignedInteger('term_months');
            $table->decimal('monthly_repayment', 15, 2)->default(0);
            $table->decimal('total_repaid', 15, 2)->default(0);
            $table->enum('status', ['pending', 'approved', 'disbursed', 'repaying', 'completed', 'defaulted'])->default('pending');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
