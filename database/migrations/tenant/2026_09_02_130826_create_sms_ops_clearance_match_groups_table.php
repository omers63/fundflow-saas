<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_ops_clearance_match_groups', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('cleared_at');
            $table->timestamps();
        });

        Schema::table('sms_transactions', function (Blueprint $table): void {
            $table->foreignId('sms_ops_clearance_match_group_id')
                ->nullable()
                ->after('bank_cleared_at')
                ->constrained('sms_ops_clearance_match_groups')
                ->nullOnDelete();
            $table->boolean('is_ops_cleared')->default(false)->after('sms_ops_clearance_match_group_id');
            $table->timestamp('ops_cleared_at')->nullable()->after('is_ops_cleared');
            $table->foreignId('master_bank_transaction_id')
                ->nullable()
                ->after('ops_cleared_at')
                ->constrained('transactions')
                ->nullOnDelete();
        });

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->foreignId('sms_ops_clearance_match_group_id')
                ->nullable()
                ->after('sms_clearance_match_group_id')
                ->constrained('sms_ops_clearance_match_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sms_ops_clearance_match_group_id');
        });

        Schema::table('sms_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('master_bank_transaction_id');
            $table->dropConstrainedForeignId('sms_ops_clearance_match_group_id');
            $table->dropColumn(['is_ops_cleared', 'ops_cleared_at']);
        });

        Schema::dropIfExists('sms_ops_clearance_match_groups');
    }
};
