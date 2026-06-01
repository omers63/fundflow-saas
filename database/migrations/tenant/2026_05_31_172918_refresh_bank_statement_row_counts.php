<?php

use App\Models\Tenant\BankStatement;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        BankStatement::query()->eachById(function (BankStatement $statement): void {
            $statement->refreshRowCounts();
        });
    }

    public function down(): void
    {
        // Counts are denormalized; no rollback required.
    }
};
