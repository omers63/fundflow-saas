<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE loans MODIFY COLUMN status ENUM(
            'pending',
            'approved',
            'active',
            'completed',
            'early_settled',
            'rejected',
            'cancelled',
            'disbursed',
            'repaying',
            'defaulted'
        ) NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE loans MODIFY COLUMN status ENUM(
            'pending',
            'approved',
            'disbursed',
            'repaying',
            'completed',
            'defaulted',
            'rejected',
            'cancelled'
        ) NOT NULL DEFAULT 'pending'");
    }
};
