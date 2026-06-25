<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `members` MODIFY `status` ENUM('active', 'inactive', 'delinquent', 'suspended', 'withdrawn', 'terminated') NOT NULL DEFAULT 'active'"
            );
        }

        Schema::table('members', function (Blueprint $table): void {
            $table->boolean('contribution_cycles_active')->default(true)->after('status');
            $table->timestamp('payout_frozen_at')->nullable()->after('contribution_cycles_active');
            $table->string('status_reason', 500)->nullable()->after('payout_frozen_at');
            $table->timestamp('status_changed_at')->nullable()->after('status_reason');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn([
                'contribution_cycles_active',
                'payout_frozen_at',
                'status_reason',
                'status_changed_at',
            ]);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `members` MODIFY `status` ENUM('active', 'suspended', 'withdrawn', 'delinquent', 'terminated') NOT NULL DEFAULT 'active'"
            );
        }
    }
};
