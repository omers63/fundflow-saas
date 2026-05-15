<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['pending', 'posted', 'failed'])->default('pending');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'period']);
            $table->index('status');
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributions');
    }
};
