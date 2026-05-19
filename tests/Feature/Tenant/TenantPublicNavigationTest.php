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

    app()->setLocale('ar');

    $this->get('http://'.$domain)
        ->assertSuccessful()
        ->assertSee('tenant-public-nav', false)
        ->assertSee('tenant-public-nav__badge', false)
        ->assertSee('data-language-tooltip', false)
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

test('member login page shows shared public navigation and footer', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain.'/member/login')
        ->assertSuccessful()
        ->assertSee('tenant-public-nav', false)
        ->assertSee('tenant-public-footer', false)
        ->assertSee('tenant-public-brand-logo', false)
        ->assertSee('data-language-tooltip', false)
        ->assertSee('member-login-card', false)
        ->assertSee(__('Welcome back'), false)
        ->assertSee(route('tenant.membership', absolute: false), false)
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
        ->assertSee('tenant-public-footer', false)
        ->assertSee(__('All rights reserved.'), false)
        ->assertSee('tenant-public-brand-logo', false)
        ->assertSee('data-language-tooltip', false)
        ->assertSee('member-login-card', false)
        ->assertDontSee('fi-simple-header', false);
});

test('membership enrollment page shows shared public navigation and footer', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain.'/membership')
        ->assertSuccessful()
        ->assertSee('tenant-public-nav', false)
        ->assertSee('tenant-public-footer', false);
});
