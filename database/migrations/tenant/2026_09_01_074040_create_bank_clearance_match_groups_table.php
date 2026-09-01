<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_clearance_match_groups', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('cleared_at');
            $table->timestamps();
        });

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->foreignId('bank_clearance_match_group_id')
                ->nullable()
                ->after('cleared_at')
                ->constrained('bank_clearance_match_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_clearance_match_group_id');
        });

        Schema::dropIfExists('bank_clearance_match_groups');
    }
};
