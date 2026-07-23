<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('locale', 8);
            $table->string('channel_family', 32);
            $table->string('subject')->nullable();
            $table->text('body_markdown');
            $table->timestamps();

            $table->unique(['key', 'locale', 'channel_family']);
            $table->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
