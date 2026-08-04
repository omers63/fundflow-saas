<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\Account;
use App\Models\Tenant\User;
use App\Support\DefaultTenantSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Settings keys, fund/loan tiers, bank templates, notification templates.
        DefaultTenantSettings::seed();

        // Permanent master ledger accounts (includes Master Suspense for reconciliation).
        Account::ensureDefaultMasterAccounts();

        User::firstOrCreate(
            ['email' => 'admin@fundflow.sa'],
            ['name' => 'Fund Admin', 'email_verified_at' => now(), 'password' => Hash::make('password'), 'is_admin' => true],
        );
    }
}
