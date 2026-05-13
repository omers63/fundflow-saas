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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // Billing
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency')->default('IDR');
            $table->string('billing_cycle')->default('monthly'); // e.g. monthly, yearly
            $table->integer('duration_months')->default(1);

            $table->text('description')->nullable();

            // Flexible bucket for limits and features
            $table->json('data')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_custom')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
