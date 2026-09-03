<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_clearance_match_groups', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('cleared_at');
            $table->timestamps();
        });

        Schema::table('sms_transactions', function (Blueprint $table): void {
            $table->foreignId('sms_clearance_match_group_id')
                ->nullable()
                ->after('posted_by')
                ->constrained('sms_clearance_match_groups')
                ->nullOnDelete();
            $table->boolean('is_bank_cleared')->default(false)->after('sms_clearance_match_group_id');
            $table->timestamp('bank_cleared_at')->nullable()->after('is_bank_cleared');
        });

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->foreignId('sms_clearance_match_group_id')
                ->nullable()
                ->after('bank_clearance_match_group_id')
                ->constrained('sms_clearance_match_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sms_clearance_match_group_id');
        });

        Schema::table('sms_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sms_clearance_match_group_id');
            $table->dropColumn(['is_bank_cleared', 'bank_cleared_at']);
        });

        Schema::dropIfExists('sms_clearance_match_groups');
    }
};
