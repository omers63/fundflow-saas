<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('audience', 64);
            $table->string('title_en', 150);
            $table->string('title_ar', 150)->nullable();
            $table->text('body_en');
            $table->text('body_ar')->nullable();
            $table->json('channels');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('scheduled_for');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_announcements');
    }
};
