<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'fund_postings',
        'cash_out_requests',
        'fund_out_requests',
        'member_cash_transfer_requests',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN status ENUM(
                'pending',
                'accepted',
                'rejected',
                'cancelled'
            ) NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN status ENUM(
                'pending',
                'accepted',
                'rejected'
            ) NOT NULL DEFAULT 'pending'");
        }
    }
};
