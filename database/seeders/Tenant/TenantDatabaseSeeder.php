<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTemplate;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\ContributionPolicySettings;
use App\Support\DefaultFundAndLoanTiers;
use App\Support\NotificationTemplateCatalog;
use App\Support\PublicPageSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Setting::set('general', 'currency', 'SAR');
        PublicPageSettings::save([
            ...PublicPageSettings::defaults(),
            'fund_name_en' => 'Samman Family Fund',
            'fund_name_ar' => 'صندوق عائلةال سمان',
            'fee_new' => '150',
            'fee_resume' => '100',
            'fee_renew' => '50',
        ]);
        Setting::set('contribution', 'cycle_start_day', '6');

        ContributionPolicySettings::saveFromForm([
            'late_fee_contribution_1d' => 10,
            'late_fee_contribution_10d' => 50,
            'late_fee_contribution_20d' => 100,
            'late_fee_contribution_30d' => 150,
            'late_fee_repayment_1d' => 10,
            'late_fee_repayment_10d' => 50,
            'late_fee_repayment_20d' => 100,
            'late_fee_repayment_30d' => 150,
        ]);

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

        $user = User::firstOrCreate(
            ['email' => 'admin@fundflow.sa'],
            ['name' => 'Fund Admin', 'email_verified_at' => now(), 'password' => Hash::make('password'), 'is_admin' => true],
        );

        // $accounting = app(AccountingService::class);

        // $parentUser = User::firstOrCreate(
        //     ['email' => 'family@fund.test', 'name' => 'John Doe'],
        //     ['email_verified_at' => now(), 'password' => Hash::make('password'), 'is_admin' => false],
        // );
        // $parent = Member::firstOrCreate(
        //     ['member_number' => 'MEM-0001'],
        //     [
        //         'user_id' => $parentUser->id,
        //         'name' => 'John Doe',
        //         'email' => 'family@fund.test',
        //         'household_email' => 'family@fund.test',
        //         'phone' => '+1234567890',
        //         'monthly_contribution_amount' => 5000,
        //         'joined_at' => now()->subMonths(18),
        //         'status' => 'active',
        //         'portal_pin' => Hash::make('1234'),
        //     ],
        // );
        // if ($parent->wasRecentlyCreated) {
        //     $accounting->createMemberAccounts($parent);
        // }

        // $dependentUser = User::firstOrCreate(
        //     ['email' => 'family@fund.test', 'name' => 'Jane Doe'],
        //     ['email_verified_at' => now(), 'password' => Hash::make('dependent'), 'is_admin' => false],
        // );
        // if (! $dependentUser->wasRecentlyCreated) {
        //     $dependentUser->update(['password' => Hash::make('dependent')]);
        // }
        // $dependent = Member::firstOrCreate(
        //     ['member_number' => 'MEM-0002'],
        //     [
        //         'user_id' => $dependentUser->id,
        //         'parent_member_id' => $parent->id,
        //         'name' => 'Jane Doe',
        //         'email' => 'family@fund.test',
        //         'household_email' => 'family@fund.test',
        //         'phone' => '+1234567891',
        //         'monthly_contribution_amount' => 2000,
        //         'joined_at' => now()->subMonths(12),
        //         'status' => 'active',
        //     ],
        // );
        // if ($dependent->wasRecentlyCreated) {
        //     $accounting->createMemberAccounts($dependent);
        // }

        // $member2User = User::firstOrCreate(
        //     ['email' => 'bob@fund.test'],
        //     ['name' => 'Bob Smith', 'email_verified_at' => now(), 'password' => Hash::make('password'), 'is_admin' => false],
        // );
        // $member2 = Member::firstOrCreate(
        //     ['member_number' => 'MEM-0003'],
        //     ['user_id' => $member2User->id, 'name' => 'Bob Smith', 'email' => 'bob@fund.test', 'phone' => '+1234567892', 'monthly_contribution_amount' => 3000, 'joined_at' => now()->subMonths(6), 'status' => 'active'],
        // );
        // if ($member2->wasRecentlyCreated) {
        //     $accounting->createMemberAccounts($member2);
        // }
    }
}
