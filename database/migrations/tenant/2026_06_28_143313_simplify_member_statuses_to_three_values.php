<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::table('members')->where('status', 'delinquent')->update(['status' => 'active']);
            DB::table('members')->where('status', 'suspended')->update(['status' => 'inactive']);
            DB::table('members')->where('status', 'terminated')->update(['status' => 'withdrawn']);

            DB::statement(
                "ALTER TABLE `members` MODIFY `status` ENUM('active', 'inactive', 'withdrawn') NOT NULL DEFAULT 'active'"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `members` MODIFY `status` ENUM('active', 'inactive', 'delinquent', 'suspended', 'withdrawn', 'terminated') NOT NULL DEFAULT 'active'"
            );
        }
    }
};
