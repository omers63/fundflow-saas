<?php

use App\Livewire\Tenant\MemberLoginPage;
use App\Models\Central\Tenant;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    User::query()->delete();
    Member::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $this->memberUser = User::create([
        'name' => 'Alice Member',
        'email' => 'alice@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-A001',
        'name' => 'Alice Member',
        'email' => 'alice@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
});

test('member login page renders reference-style card', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain.'/member/login')
        ->assertSuccessful()
        ->assertSee('member-login-card', false)
        ->assertSee('member-login-cta', false)
        ->assertSee('Ready to Join Your Family Fund', false)
        ->assertSee(__('Welcome back'), false)
        ->assertSee(__('Sign in to your member portal account'), false)
        ->assertSee(__('Not a member yet?'), false)
        ->assertDontSee('fi-simple-header', false);
});

test('member can sign in through custom login page', function () {
    Livewire::test(MemberLoginPage::class)
        ->set('email', 'alice@fund.test')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect('/member');

    expect(auth('tenant')->id())->toBe($this->memberUser->id);
});

test('admin without member profile is redirected to tenant admin panel from member login', function () {
    $adminUser = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'admin@fund.test')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect('/admin');

    expect(auth('tenant')->id())->toBe($adminUser->id);
});

test('admin who is also a member is redirected to member portal from member login', function () {
    $adminMemberUser = User::create([
        'name' => 'Admin Member',
        'email' => 'admin-member@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Member::create([
        'user_id' => $adminMemberUser->id,
        'member_number' => 'MEM-ADM01',
        'name' => 'Admin Member',
        'email' => 'admin-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($adminMemberUser->member);

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'admin-member@fund.test')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect('/member');

    expect(auth('tenant')->id())->toBe($adminMemberUser->id);
});

test('/login redirects to member login', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain.'/login')
        ->assertRedirect('/member/login');
});
