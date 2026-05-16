<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('direct_messages')->nullOnDelete();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['to_user_id', 'read_at']);
            $table->index(['from_user_id']);
            $table->index(['parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_messages');
    }
};
