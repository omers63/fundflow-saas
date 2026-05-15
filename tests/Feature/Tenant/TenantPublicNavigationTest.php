<?php

use App\Models\Central\Tenant;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('landing page shows shared public navigation and footer', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain)
        ->assertSuccessful()
        ->assertSee('tenant-public-nav', false)
        ->assertSee(__('Member login'), false)
        ->assertSee(__('Check status'), false)
        ->assertSee('tenant-public-footer', false)
        ->assertSee(__('Quick links'), false)
        ->assertSee(__('Contact'), false)
        ->assertSee(__('Membership Management'), false)
        ->assertSee(__('Monthly Statements'), false)
        ->assertSee(__('Smart Notifications'), false)
        ->assertSee('Admin dashboard', false)
        ->assertSee('transparent accounting', false)
        ->assertDontSee('>Transparent Accounting<', false);
});

test('member login page shows shared public navigation and footer', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain.'/member/login')
        ->assertSuccessful()
        ->assertSee('tenant-public-nav', false)
        ->assertSee('member-login-card', false)
        ->assertSee(__('Welcome back'), false)
        ->assertSee(__('Home'), false)
        ->assertSee(route('tenant.membership', absolute: false), false)
        ->assertSee('tenant-public-footer', false)
        ->assertSee(__('Apply for membership'), false)
        ->assertDontSee('fi-simple-header', false);
});

test('tenant admin login page shows shared public navigation and footer', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain.'/admin/login')
        ->assertSuccessful()
        ->assertSee('tenant-public-nav', false)
        ->assertSee(__('Apply'), false)
        ->assertSee('tenant-public-footer', false)
        ->assertSee(__('All rights reserved.'), false);
});
