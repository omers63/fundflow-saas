<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTemplate;
use App\Models\Tenant\User;
use App\Support\DefaultFundAndLoanTiers;
use App\Support\DefaultTenantSettings;
use App\Support\NotificationTemplateCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Persist Settings-page defaults (Samman production policy shape) for every tab.
        DefaultTenantSettings::seed();

        // Permanent master ledger accounts (includes Master Suspense for reconciliation).
        Account::ensureDefaultMasterAccounts();

        DefaultFundAndLoanTiers::seedIfEmpty();

        if (Schema::hasTable('notification_templates')) {
            NotificationTemplateCatalog::seedMissingDefaults();
        }

        BankTemplate::firstOrCreate(
            ['name' => 'Generic CSV'],
            [
                'encoding' => 'UTF-8',
                'delimiter' => ',',
                'has_header' => true,
                'skip_rows' => 0,
                'date_format' => 'Y-m-d',
                'date_column' => '0',
                'amount_column' => '2',
                'amount_mode' => 'single',
                'credit_column' => null,
                'debit_column' => null,
                'extra_columns' => BankTemplate::defaultExtraColumns(),
                'duplicate_fields' => ['date', 'amount', 'description', 'reference'],
                'duplicate_date_tolerance' => 0,
                'is_default' => false,
            ],
        );

        BankTemplate::firstOrCreate(
            ['name' => 'Al Rajhi Bank'],
            [
                'encoding' => 'UTF-8',
                'delimiter' => ',',
                'has_header' => true,
                'skip_rows' => 15,
                'date_format' => ['d-m-Y', 'd/m/Y'],
                'date_column' => 'التاريخ الميلادي',
                'amount_column' => null,
                'amount_mode' => 'split',
                'credit_column' => 'دائن',
                'debit_column' => 'مدين',
                'extra_columns' => [
                    ['key' => 'البيان', 'column' => 'البيان'],
                    ['key' => 'ملاحظات', 'column' => 'ملاحظات'],
                    ['key' => 'تصنيف العملية', 'column' => 'تصنيف العملية'],
                    ['key' => 'التاريخ الهجري', 'column' => 'التاريخ الهجري'],
                    ['key' => 'الرصيد', 'column' => 'الرصيد'],
                ],
                'duplicate_fields' => ['date', 'amount', 'البيان', 'ملاحظات', 'تصنيف العملية', 'التاريخ الهجري', 'الرصيد'],
                'duplicate_date_tolerance' => 0,
                'is_default' => true,
            ],
        );

        User::firstOrCreate(
            ['email' => 'admin@fundflow.sa'],
            ['name' => 'Fund Admin', 'email_verified_at' => now(), 'password' => Hash::make('password'), 'is_admin' => true],
        );
    }
}
