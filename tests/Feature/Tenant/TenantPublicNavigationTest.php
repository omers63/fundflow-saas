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

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    app()->setLocale('ar');

    $this->get('http://' . $domain)
        ->assertSuccessful()
        ->assertSee('tenant-public-nav', false)
        ->assertSee('tenant-public-nav__badge', false)
        ->assertSee('tenant-public-brand-logo', false)
        ->assertSee(__('Member login', locale: 'ar'), false)
        ->assertSee(__('Check application status', locale: 'ar'), false)
        ->assertSee('tenant-public-footer', false)
        ->assertSee(__('Quick links', locale: 'ar'), false)
        ->assertSee(__('Contact', locale: 'ar'), false)
        ->assertSee(__('All rights reserved.', locale: 'ar'), false)
        ->assertSee(__('Membership Management', locale: 'ar'), false)
        ->assertSee(__('Monthly Statements', locale: 'ar'), false)
        ->assertSee(__('Smart Notifications', locale: 'ar'), false)
        ->assertSee(__('Admin dashboard & transparent accounting', locale: 'ar'), false)
        ->assertDontSee('>Transparent Accounting<', false);
});

test('member login page does not show public navigation or footer', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://' . $domain . '/member/login')
        ->assertSuccessful()
        ->assertDontSee('tenant-public-nav', false)
        ->assertDontSee('tenant-public-footer', false)
        ->assertSee('member-login-card', false)
        ->assertSee(__('Welcome back'), false)
        ->assertSee(route('tenant.membership', absolute: false), false)
        ->assertSee(__('Apply for membership'), false)
        ->assertDontSee('fi-simple-header', false);
});

test('tenant admin login page does not show public navigation or footer', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://' . $domain . '/admin/login')
        ->assertSuccessful()
        ->assertDontSee('tenant-public-nav', false)
        ->assertDontSee('tenant-public-footer', false)
        ->assertDontSee(__('All rights reserved.'), false)
        ->assertSee('member-login-card', false)
        ->assertDontSee('fi-simple-header', false);
});
