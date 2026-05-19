<?php

use App\Livewire\Tenant\TenantAdminLoginPage;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    User::query()->delete();

    $this->adminUser = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Alice Member',
        'email' => 'alice@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);
});

test('tenant admin login page renders styled auth card', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain.'/admin/login')
        ->assertSuccessful()
        ->assertSee('member-login-card', false)
        ->assertSee('member-login-card--admin', false)
        ->assertSee(__('Sign in to the fund administration dashboard'), false)
        ->assertSee(__('Member portal'), false)
        ->assertSee('tenant-public-nav', false)
        ->assertSee('tenant-public-footer', false)
        ->assertDontSee('fi-simple-header', false);
});

test('admin can sign in through custom login page', function () {
    Livewire::test(TenantAdminLoginPage::class)
        ->set('email', 'admin@fund.test')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect('/admin');

    expect(auth('tenant')->id())->toBe($this->adminUser->id);
});

test('non-admin cannot sign in through tenant admin login page', function () {
    Livewire::test(TenantAdminLoginPage::class)
        ->set('email', 'alice@fund.test')
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors(['email']);

    expect(auth('tenant')->check())->toBeFalse();
});
