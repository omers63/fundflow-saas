<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberImportService;
use App\Services\MembershipApplicationApprovalService;
use App\Services\MembershipSubscriptionFeeService;
use App\Services\ReconciliationReportService;
use App\Services\Tenant\MemberMembershipProfileService;
use App\Support\PublicPageSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    MembershipApplication::query()->delete();
    Member::query()->delete();
    Transaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_new' => '150',
    ]);
});

function writeLegacyMemberFeeCsv(string $contents): string
{
    $path = sys_get_temp_dir().'/member-fee-import-'.uniqid('', true).'.csv';
    file_put_contents($path, $contents);

    return $path;
}

test('member import posts subscription fee legs for declared application fees', function () {
    $admin = User::create([
        'name' => 'Import Admin',
        'email' => 'fee-import-admin@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_new' => '50',
    ]);

    $path = writeLegacyMemberFeeCsv(
        "member_number,name,email,application_fee_amount,application_fee_transfer_date,application_fee_transfer_reference\n".
        "FEE-1,Fee Import Member,fee.import@fund.test,75,2024-05-20,FEE-CSV-1\n"
    );

    $result = app(MemberImportService::class)->import($path, 'TempPass@123');

    expect($result['created'])->toBe(1)->and($result['failed'])->toBe(0);

    $member = Member::query()->where('member_number', 'FEE-1')->first();
    $profile = app(MemberMembershipProfileService::class)->findForMember($member);

    expect($member)->not->toBeNull()
        ->and($profile)->not->toBeNull()
        ->and((float) $profile->membership_fee_amount)->toBe(75.0)
        // residual cash after deposit 75 and required fee 50
        ->and((float) $member->cashAccount->fresh()->balance)->toBe(25.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(25.0)
        ->and((float) Account::masterFees()->fresh()->balance)->toBe(50.0)
        ->and(
            Transaction::query()
                ->where('reference_type', MembershipApplication::class)
                ->where('reference_id', $profile->id)
                ->count()
        )->toBeGreaterThan(0);

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    expect($report['checks']['membership_application_fee_integrity']['severity'])->toBe('ok')
        ->and($report['checks']['membership_application_fee_integrity']['issue_count'])->toBe(0);
});

test('on-behalf dependent without fee posting path does not fail integrity', function () {
    $parent = Member::create([
        'member_number' => 'PAR-DEP-1',
        'name' => 'Parent Sponsor',
        'email' => 'parent-dep@fund.test',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($parent);

    $application = MembershipApplication::create([
        'name' => 'Dependent Child',
        'email' => 'child-dep@fund.test',
        'password' => 'SecurePass1!',
        'phone' => '0500000002',
        'mobile_phone' => '0500000002',
        'application_type' => 'new',
        'national_id' => '1987654321',
        'date_of_birth' => '2010-01-01',
        'address' => 'Somewhere',
        'city' => 'Riyadh',
        'bank_account_number' => '1234567890123456',
        'iban' => 'SA0380000000608010167519',
        'parent_member_id' => $parent->id,
        'membership_fee_amount' => 150,
        'membership_fee_required_amount' => 150,
        'membership_fee_transfer_reference' => 'NOT-POSTED',
        'status' => 'pending',
    ]);

    app(MembershipApplicationApprovalService::class)->approve($application);

    expect(Transaction::query()
        ->where('reference_type', MembershipApplication::class)
        ->where('reference_id', $application->id)
        ->count())->toBe(0);

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    expect($report['checks']['membership_application_fee_integrity']['severity'])->toBe('ok')
        ->and($report['checks']['membership_application_fee_integrity']['issue_count'])->toBe(0);
});

test('approved enrollment missing deposit mirror legs is critical', function () {
    $application = MembershipApplication::create([
        'name' => 'Enroll Missing Legs',
        'email' => 'enroll-missing@fund.test',
        'password' => 'SecurePass1!',
        'phone' => '0500000003',
        'mobile_phone' => '0500000003',
        'application_type' => 'new',
        'national_id' => '1099887766',
        'date_of_birth' => '1990-01-01',
        'address' => 'Somewhere',
        'city' => 'Riyadh',
        'bank_account_number' => '1234567890123456',
        'iban' => 'SA0380000000608010167519',
        'membership_fee_amount' => 150,
        'membership_fee_required_amount' => 150,
        'membership_fee_transfer_reference' => 'TXN-MISSING',
        'membership_fee_transfer_date' => now()->toDateString(),
        'status' => 'pending',
    ]);

    $member = app(MembershipApplicationApprovalService::class)->approve($application);
    Transaction::query()
        ->where('reference_type', MembershipApplication::class)
        ->where('reference_id', $application->id)
        ->delete();
    $member->cashAccount?->update(['balance' => 0]);
    Account::masterCash()?->update(['balance' => 0]);
    Account::masterFees()?->update(['balance' => 0]);

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    expect($report['checks']['membership_application_fee_integrity']['severity'])->toBe('critical')
        ->and($report['checks']['membership_application_fee_integrity']['issue_count'])->toBeGreaterThan(0);
});

test('backfill posts missing legacy profile fee legs', function () {
    $member = Member::create([
        'member_number' => 'BF-1',
        'name' => 'Backfill Member',
        'email' => 'backfill@fund.test',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $profile = app(MemberMembershipProfileService::class)->syncFromImportAttributes($member, [
        'membership_fee_amount' => 150,
        'membership_fee_transfer_date' => '2025-05-10',
    ]);

    expect(Transaction::query()
        ->where('reference_type', MembershipApplication::class)
        ->where('reference_id', $profile->id)
        ->count())->toBe(0);

    $count = app(MembershipSubscriptionFeeService::class)->backfillMissingLegacyMemberImportFees();

    expect($count)->toBe(1)
        ->and((float) Account::masterFees()->fresh()->balance)->toBe(150.0)
        ->and((float) $member->cashAccount->fresh()->balance)->toBe(0.0);
});
