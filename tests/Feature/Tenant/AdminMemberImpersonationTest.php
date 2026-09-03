<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Tenant\ImpersonationService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    User::query()->delete();
    Member::query()->delete();

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $this->baseUrl = 'http://' . $this->domain;

    $this->admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin-impersonate@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Portal Member',
        'email' => 'member-impersonate@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-IMP-01',
        'name' => 'Portal Member',
        'email' => 'member-impersonate@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->orphanMember = Member::create([
        'member_number' => 'MEM-IMP-02',
        'name' => 'No Login Member',
        'email' => 'orphan-impersonate@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
});

test('admin can impersonate a member and land on the member portal', function () {
    $this->actingAs($this->admin, 'tenant');

    $response = $this->get($this->baseUrl . route('tenant.admin.members.impersonate', [
        'member' => $this->member,
    ], false));

    $response->assertRedirect(Filament::getPanel('member')?->getUrl() ?? '/member');

    expect(auth('tenant')->id())->toBe($this->memberUser->id)
        ->and(session('impersonator_user_id'))->toBe($this->admin->id)
        ->and(session('impersonated_member_id'))->toBe($this->member->id)
        ->and(session('impersonation_source'))->toBe(ImpersonationService::SOURCE_ADMIN)
        ->and(session('impersonation_return_panel'))->toBe(ImpersonationService::RETURN_PANEL_TENANT)
        ->and(session('impersonation_return_url'))->toContain('/admin/members/' . $this->member->id);

    $this->get($this->baseUrl . '/member')
        ->assertSuccessful()
        ->assertSee(__('Return to admin portal'), false);
});

test('stopping admin impersonation restores the member view page', function () {
    $this->actingAs($this->admin, 'tenant');

    Filament::setCurrentPanel('tenant');
    $memberViewUrl = MemberResource::getUrl('view', [
        'record' => $this->member,
    ], panel: 'tenant');

    app(ImpersonationService::class)->start(
        $this->admin,
        $this->memberUser,
        $this->member,
        ImpersonationService::SOURCE_ADMIN,
        $memberViewUrl,
    );

    expect(session('impersonation_return_url'))->toContain('/admin/members/' . $this->member->id);

    $response = $this->post($this->baseUrl . route('tenant.member.impersonation.stop', [], false));

    $response->assertRedirect('/admin/members/' . $this->member->id);

    expect(auth('tenant')->id())->toBe($this->admin->id)
        ->and(session('impersonator_user_id'))->toBeNull()
        ->and(session('impersonation_source'))->toBeNull()
        ->and(session('impersonation_return_panel'))->toBeNull()
        ->and(session('impersonation_return_url'))->toBeNull();
});

test('impersonation start and stop keep the same session id', function () {
    $this->actingAs($this->admin, 'tenant');

    $sessionIdBefore = session()->getId();

    app(ImpersonationService::class)->start(
        $this->admin,
        $this->memberUser,
        $this->member,
        ImpersonationService::SOURCE_ADMIN,
        '/admin/members/' . $this->member->id,
    );

    expect(session()->getId())->toBe($sessionIdBefore)
        ->and(auth('tenant')->id())->toBe($this->memberUser->id);

    app(ImpersonationService::class)->stop();

    expect(session()->getId())->toBe($sessionIdBefore)
        ->and(auth('tenant')->id())->toBe($this->admin->id);
});

test('non-admin cannot start admin member impersonation', function () {
    $this->actingAs($this->memberUser, 'tenant');

    $this->get($this->baseUrl . route('tenant.admin.members.impersonate', [
        'member' => $this->member,
    ], false))->assertForbidden();
});

test('admin cannot impersonate a member without a portal login', function () {
    $this->actingAs($this->admin, 'tenant');

    $response = $this->get($this->baseUrl . route('tenant.admin.members.impersonate', [
        'member' => $this->orphanMember,
    ], false));

    $response->assertRedirect();
    expect(auth('tenant')->id())->toBe($this->admin->id)
        ->and(session('impersonator_user_id'))->toBeNull();
});

test('admin can impersonate a withdrawn member with a portal login', function () {
    $this->member->update(['status' => 'withdrawn']);

    $this->actingAs($this->admin, 'tenant');

    $response = $this->get($this->baseUrl . route('tenant.admin.members.impersonate', [
        'member' => $this->member,
    ], false));

    $response->assertRedirect(Filament::getPanel('member')?->getUrl() ?? '/member');

    expect(auth('tenant')->id())->toBe($this->memberUser->id)
        ->and(session('impersonation_source'))->toBe(ImpersonationService::SOURCE_ADMIN);

    $this->get($this->baseUrl . '/member')
        ->assertSuccessful()
        ->assertSee(__('Return to admin portal'), false);
});

test('dependent impersonation returns to the page where it started', function () {
    $parentUser = User::create([
        'name' => 'Parent Impersonator',
        'email' => 'parent-impersonate@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $parent = Member::create([
        'user_id' => $parentUser->id,
        'member_number' => 'MEM-IMP-P',
        'name' => 'Parent Impersonator',
        'email' => 'parent-impersonate@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);

    $this->member->update(['parent_member_id' => $parent->id]);

    $startedFrom = '/member/dependents?tab=list';

    app(ImpersonationService::class)->start(
        $parentUser,
        $this->memberUser,
        $this->member,
        ImpersonationService::SOURCE_MEMBER_DEPENDENTS,
        $startedFrom,
    );

    expect(app(ImpersonationService::class)->returnActionLabel())->toBe(__('Return to parent portal'));

    $response = $this->post($this->baseUrl . route('tenant.member.impersonation.stop', [], false));

    $response->assertRedirect($startedFrom);
    expect(auth('tenant')->id())->toBe($parentUser->id);
});

test('nested admin then dependent impersonation unwinds parent then admin', function () {
    $parentUser = User::create([
        'name' => 'Nested Parent',
        'email' => 'nested-parent@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $parent = Member::create([
        'user_id' => $parentUser->id,
        'member_number' => 'MEM-IMP-NP',
        'name' => 'Nested Parent',
        'email' => 'nested-parent@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);

    $dependentUser = User::create([
        'name' => 'Nested Dependent',
        'email' => 'nested-dependent@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $dependent = Member::create([
        'user_id' => $dependentUser->id,
        'member_number' => 'MEM-IMP-ND',
        'name' => 'Nested Dependent',
        'email' => 'nested-dependent@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'parent_member_id' => $parent->id,
    ]);

    Filament::setCurrentPanel('tenant');
    $parentAdminView = MemberResource::getUrl('view', ['record' => $parent], panel: 'tenant');
    $dependentsPage = '/member/my-dependents';

    app(ImpersonationService::class)->start(
        $this->admin,
        $parentUser,
        $parent,
        ImpersonationService::SOURCE_ADMIN,
        $parentAdminView,
    );

    expect(auth('tenant')->id())->toBe($parentUser->id)
        ->and(session('impersonation_source'))->toBe(ImpersonationService::SOURCE_ADMIN);

    // Reproduce production: default Filament panel is central "admin", so getUrl()
    // without an explicit member panel must not look up filament.admin.resources.*.
    Filament::setCurrentPanel('admin');

    $nestedStart = $this->from($this->baseUrl . $dependentsPage)->get(
        $this->baseUrl . route('tenant.member.dependents.impersonate', ['dependent' => $dependent], false),
    );

    $nestedStart->assertRedirect(Filament::getPanel('member')?->getUrl() ?? '/member');

    expect(auth('tenant')->id())->toBe($dependentUser->id)
        ->and(session('impersonator_user_id'))->toBe($parentUser->id)
        ->and(session('impersonation_source'))->toBe(ImpersonationService::SOURCE_MEMBER_DEPENDENTS)
        ->and(session('impersonation_return_url'))->toBe($dependentsPage)
        ->and(app(ImpersonationService::class)->returnActionLabel())->toBe(__('Return to parent portal'));

    $firstStop = $this->post($this->baseUrl . route('tenant.member.impersonation.stop', [], false));

    $firstStop->assertRedirect($dependentsPage);

    expect(auth('tenant')->id())->toBe($parentUser->id)
        ->and(session('impersonator_user_id'))->toBe($this->admin->id)
        ->and(session('impersonated_member_id'))->toBe($parent->id)
        ->and(session('impersonation_source'))->toBe(ImpersonationService::SOURCE_ADMIN)
        ->and(session('impersonation_return_url'))->toContain('/admin/members/' . $parent->id)
        ->and(app(ImpersonationService::class)->returnActionLabel())->toBe(__('Return to admin portal'));

    $secondStop = $this->post($this->baseUrl . route('tenant.member.impersonation.stop', [], false));

    $secondStop->assertRedirect('/admin/members/' . $parent->id);

    expect(auth('tenant')->id())->toBe($this->admin->id)
        ->and(session('impersonator_user_id'))->toBeNull()
        ->and(session('impersonation_stack'))->toBeNull();
});
