<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\MembershipApplicationApprovalService;
use App\Services\MembershipApplicationImportService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    MembershipApplication::query()->delete();
    Member::query()->delete();

    foreach ([
        ['type' => 'cash', 'name' => 'Master Cash'],
        ['type' => 'fund', 'name' => 'Master Fund'],
        ['type' => 'bank', 'name' => 'Master Bank'],
        ['type' => 'fees', 'name' => 'Master Fees'],
    ] as $account) {
        Account::create(['type' => $account['type'], 'name' => $account['name'], 'balance' => 0, 'is_master' => true]);
    }
});

test('csv import stores cut-off date and optional balances from columns', function () {
    $admin = User::create([
        'name' => 'Import Admin',
        'email' => 'import-cutoff@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $csv = implode("\n", [
        'name,email,mobile_phone,iban,cutoff_cash_balance,cutoff_fund_balance',
        'Cutoff Applicant,cutoff@example.test,0501000777,SA030000000000101000000077,250.50,100',
    ]);

    $path = storage_path('app/testing-cutoff-import.csv');
    file_put_contents($path, $csv);

    $result = app(MembershipApplicationImportService::class)->import($path, 'DefaultPass1', '2024-06-15');

    expect($result['created'])->toBe(1)
        ->and($result['failed'])->toBe(0);

    $application = MembershipApplication::query()->where('email', 'cutoff@example.test')->first();

    expect($application)->not->toBeNull()
        ->and($application->import_arrears_cutoff_date?->toDateString())->toBe('2024-06-15')
        ->and((float) $application->import_cutoff_cash_balance)->toBe(250.50)
        ->and((float) $application->import_cutoff_fund_balance)->toBe(100.0);

    @unlink($path);
});

test('approving imported application posts cut-off balances and limits contribution arrears', function () {
    $admin = User::create([
        'name' => 'Approve Cutoff Admin',
        'email' => 'approve-cutoff@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $csv = implode("\n", [
        'name,email,mobile_phone,iban,membership_date,cutoff_cash_balance,cutoff_fund_balance',
        'Legacy Member,legacy@example.test,0501000888,SA030000000000101000000088,2018-01-01,300,150',
    ]);

    $path = storage_path('app/testing-cutoff-approve.csv');
    file_put_contents($path, $csv);

    app(MembershipApplicationImportService::class)->import($path, 'DefaultPass1', '2024-06-01');

    $application = MembershipApplication::query()->where('email', 'legacy@example.test')->firstOrFail();

    $member = app(MembershipApplicationApprovalService::class)->approve($application);

    $member->refresh();

    expect($member->contribution_arrears_cutoff_date?->toDateString())->toBe('2024-06-01')
        ->and((float) $member->opening_cash_balance)->toBe(300.0)
        ->and((float) $member->opening_fund_balance)->toBe(150.0)
        ->and($member->opening_balances_posted_at)->not->toBeNull()
        ->and((float) $member->cashAccount->balance)->toBeGreaterThanOrEqual(0.0)
        ->and((float) $member->fundAccount->balance)->toBeGreaterThanOrEqual(150.0);

    expect($member->joined_at?->toDateString())->toBe('2018-01-01')
        ->and($member->contributionLiabilityStartMonth()?->toDateString())->toBe('2024-06-01');

    @unlink($path);
});
