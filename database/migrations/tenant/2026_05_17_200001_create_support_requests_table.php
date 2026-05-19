<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('category', 64);
            $table->string('subject', 150);
            $table->text('message');
            $table->timestamps();

            $table->index('created_at');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
    }
};
