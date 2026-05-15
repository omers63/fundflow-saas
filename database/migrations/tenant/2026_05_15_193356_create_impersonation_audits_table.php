<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impersonator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('impersonated_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('impersonated_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->enum('event', ['started', 'stopped']);
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_audits');
    }
};
