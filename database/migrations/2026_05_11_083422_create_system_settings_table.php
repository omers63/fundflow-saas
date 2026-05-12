<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_name')->default('FundFlow');
            $table->string('support_email')->nullable();
            $table->string('public_hero_title')->default('Family sponsorship made simple');
            $table->text('public_hero_subtitle')->nullable();
            $table->string('public_primary_color')->default('#4f46e5');
            $table->string('public_secondary_color')->default('#0ea5e9');
            $table->string('admin_primary_color')->default('#4f46e5');
            $table->string('member_primary_color')->default('#0284c7');
            $table->boolean('maintenance_enabled')->default(false);
            $table->text('maintenance_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
