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
        Schema::create('bank_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('delimiter')->default(',');
            $table->boolean('has_header')->default(true);
            $table->unsignedInteger('skip_rows')->default(0);
            $table->string('date_format')->default('Y-m-d');
            $table->string('date_column')->default('0');
            $table->string('description_column')->default('1');
            $table->string('amount_column')->default('2');
            $table->string('reference_column')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_templates');
    }
};
