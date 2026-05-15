<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTemplate;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\PublicPageSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Setting::set('general', 'currency', 'USD');
        Setting::set('general', 'fund_name', 'Family Fund');
        Setting::set('contribution', 'cycle_start_day', '6');

        PublicPageSettings::save(PublicPageSettings::defaults());

        BankTemplate::firstOrCreate(
            ['name' => 'Default CSV'],
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
                'is_default' => true,
            ],
        );

        $masterAccounts = [
            ['type' => 'cash', 'name' => 'Master Cash'],
            ['type' => 'fund', 'name' => 'Master Fund'],
            ['type' => 'bank', 'name' => 'Master Bank'],
            ['type' => 'expense', 'name' => 'Master Expense'],
            ['type' => 'fees', 'name' => 'Master Fees'],
            ['type' => 'invest', 'name' => 'Master Invest'],
        ];

        foreach ($masterAccounts as $account) {
            Account::firstOrCreate(
                ['type' => $account['type'], 'is_master' => true],
                ['member_id' => null, 'name' => $account['name'], 'balance' => 0],
            );
        }

        $user = User::firstOrCreate(
            ['email' => 'admin@fund.test'],
            ['name' => 'Fund Admin', 'email_verified_at' => now(), 'password' => Hash::make('password'), 'is_admin' => true],
        );

        $accounting = app(AccountingService::class);

        $parentUser = User::firstOrCreate(
            ['email' => 'family@fund.test', 'name' => 'John Doe'],
            ['email_verified_at' => now(), 'password' => Hash::make('password'), 'is_admin' => false],
        );
        $parent = Member::firstOrCreate(
            ['member_number' => 'MEM-0001'],
            [
                'user_id' => $parentUser->id,
                'name' => 'John Doe',
                'email' => 'family@fund.test',
                'household_email' => 'family@fund.test',
                'phone' => '+1234567890',
                'monthly_contribution_amount' => 5000,
                'joined_at' => now()->subMonths(18),
                'status' => 'active',
                'portal_pin' => Hash::make('1234'),
            ],
        );
        if ($parent->wasRecentlyCreated) {
            $accounting->createMemberAccounts($parent);
        }

        $dependentUser = User::firstOrCreate(
            ['email' => 'family@fund.test', 'name' => 'Jane Doe'],
            ['email_verified_at' => now(), 'password' => Hash::make('dependent'), 'is_admin' => false],
        );
        if (! $dependentUser->wasRecentlyCreated) {
            $dependentUser->update(['password' => Hash::make('dependent')]);
        }
        $dependent = Member::firstOrCreate(
            ['member_number' => 'MEM-0002'],
            [
                'user_id' => $dependentUser->id,
                'parent_member_id' => $parent->id,
                'name' => 'Jane Doe',
                'email' => 'family@fund.test',
                'household_email' => 'family@fund.test',
                'phone' => '+1234567891',
                'monthly_contribution_amount' => 2000,
                'joined_at' => now()->subMonths(12),
                'status' => 'active',
            ],
        );
        if ($dependent->wasRecentlyCreated) {
            $accounting->createMemberAccounts($dependent);
        }

        $member2User = User::firstOrCreate(
            ['email' => 'bob@fund.test'],
            ['name' => 'Bob Smith', 'email_verified_at' => now(), 'password' => Hash::make('password'), 'is_admin' => false],
        );
        $member2 = Member::firstOrCreate(
            ['member_number' => 'MEM-0003'],
            ['user_id' => $member2User->id, 'name' => 'Bob Smith', 'email' => 'bob@fund.test', 'phone' => '+1234567892', 'monthly_contribution_amount' => 3000, 'joined_at' => now()->subMonths(6), 'status' => 'active'],
        );
        if ($member2->wasRecentlyCreated) {
            $accounting->createMemberAccounts($member2);
        }
    }
}
