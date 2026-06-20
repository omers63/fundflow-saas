<?php

use App\Models\Tenant\Account;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Account::ensureDefaultMasterAccounts();
    }

    public function down(): void
    {
        // Permanent master ledger account — do not remove on rollback.
    }
};
