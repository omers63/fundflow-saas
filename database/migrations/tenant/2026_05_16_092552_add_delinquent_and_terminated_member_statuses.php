<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `members` MODIFY `status` ENUM('active', 'suspended', 'withdrawn', 'delinquent', 'terminated') NOT NULL DEFAULT 'active'"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `members` MODIFY `status` ENUM('active', 'suspended', 'withdrawn') NOT NULL DEFAULT 'active'"
            );
        }
    }
};
