<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE contributions MODIFY COLUMN status ENUM('pending', 'posted', 'failed', 'waived') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE contributions SET status = 'pending' WHERE status = 'waived'");
        DB::statement("ALTER TABLE contributions MODIFY COLUMN status ENUM('pending', 'posted', 'failed') NOT NULL DEFAULT 'pending'");
    }
};
