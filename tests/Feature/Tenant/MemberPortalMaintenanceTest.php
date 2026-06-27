<?php

declare(strict_types=1);

use App\Livewire\Tenant\MemberLoginPage;
use App\Models\Central\Tenant;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\MemberPortalMaintenance;
use App\Support\SystemLoggingSettings;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    User::query()->delete();
    Member::query()->delete();
    FundAuditLog::query()->delete();
    MonthlyStatement::query()->delete();

    SystemLoggingSettings::setFundAuditLogEnabled(true);
    MemberPortalMaintenance::disable();

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $this->memberUser = User::create([
        'name' => 'Alice Member',
        'email' => 'alice@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-MNT-01',
        'name' => 'Alice Member',
        'email' => 'alice@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->adminUser = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
});

test('maintenance mode shows message and blocks member login', function () {
    MemberPortalMaintenance::enable('Planned database upgrade.');

    Livewire::test(MemberLoginPage::class)
        ->assertSet('statusType', 'maintenance')
        ->assertSee(__('System under maintenance'))
        ->assertSee('Planned database upgrade.')
        ->set('email', 'alice@fund.test')
        ->set('password', 'password')
        ->call('login')
        ->assertSet('statusType', 'maintenance')
        ->assertNoRedirect();

    expect(auth('tenant')->check())->toBeFalse();
});

test('admin can still sign in from member login page during maintenance', function () {
    MemberPortalMaintenance::enable();

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'admin@fund.test')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect('/admin');

    expect(auth('tenant')->id())->toBe($this->adminUser->id);
});

test('authenticated member is logged out when visiting member portal during maintenance', function () {
    $this->actingAs($this->memberUser, 'tenant');
    session([
        'active_member_id' => $this->member->id,
        MemberPortalMaintenance::SESSION_EPOCH_KEY => 0,
    ]);

    MemberPortalMaintenance::enable();

    $this->get('http://'.$this->domain.'/member')
        ->assertRedirect('/member/login');

    expect(auth('tenant')->check())->toBeFalse();
});

test('epoch bump invalidates existing member sessions', function () {
    MemberPortalMaintenance::enable();
    session([
        MemberPortalMaintenance::SESSION_EPOCH_KEY => MemberPortalMaintenance::epoch(),
    ]);

    $this->actingAs($this->memberUser, 'tenant');
    session(['active_member_id' => $this->member->id]);

    $this->get('http://'.$this->domain.'/member')
        ->assertSuccessful();

    MemberPortalMaintenance::enable('Second maintenance window.');

    $this->get('http://'.$this->domain.'/member')
        ->assertRedirect('/member/login');
});

test('admin impersonation can access member portal during maintenance', function () {
    MemberPortalMaintenance::enable();

    $this->actingAs($this->memberUser, 'tenant');
    session([
        'impersonator_user_id' => $this->adminUser->id,
        'impersonated_user_id' => $this->memberUser->id,
        'impersonated_member_id' => $this->member->id,
        'active_member_id' => $this->member->id,
        MemberPortalMaintenance::SESSION_EPOCH_KEY => 0,
    ]);

    $this->get('http://'.$this->domain.'/member')
        ->assertSuccessful();
});

test('member pdf route is blocked during maintenance', function () {
    $statement = MonthlyStatement::create([
        'member_id' => $this->member->id,
        'period' => '2026-05',
        'opening_balance' => 100,
        'total_contributions' => 50,
        'total_repayments' => 25,
        'closing_balance' => 125,
        'generated_at' => now(),
    ]);

    MemberPortalMaintenance::enable();

    $this->actingAs($this->memberUser, 'tenant');
    session([
        'active_member_id' => $this->member->id,
        MemberPortalMaintenance::SESSION_EPOCH_KEY => 0,
    ]);

    $this->get('http://'.$this->domain.'/member/statements/'.$statement->id.'/pdf')
        ->assertRedirect('/member/login');
});

test('enabling maintenance writes audit log entry', function () {
    MemberPortalMaintenance::enable('Scheduled maintenance.');

    expect(FundAuditLog::query()
        ->where('event_type', 'MEMBER_PORTAL_MAINTENANCE_ENABLED')
        ->exists())->toBeTrue();
});

test('disabling maintenance writes audit log entry', function () {
    MemberPortalMaintenance::enable();
    FundAuditLog::query()->delete();

    MemberPortalMaintenance::disable();

    expect(FundAuditLog::query()
        ->where('event_type', 'MEMBER_PORTAL_MAINTENANCE_DISABLED')
        ->exists())->toBeTrue();
});

test('member login syncs maintenance epoch on successful sign in', function () {
    Livewire::test(MemberLoginPage::class)
        ->set('email', 'alice@fund.test')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect('/member');

    expect(session(MemberPortalMaintenance::SESSION_EPOCH_KEY))->toBe(MemberPortalMaintenance::epoch());
});
