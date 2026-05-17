<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Support\AppLocale;
use App\Support\PublicPageSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('landing page shows arabic fund name when configured', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_name_en' => 'Al Noor Fund',
        'fund_name_ar' => 'صندوق النور',
    ]);

    $this->get('http://' . $domain)
        ->assertSuccessful()
        ->assertSee('tenant-public-nav__fund-name', false)
        ->assertSee('tenant-public-nav__language', false)
        ->assertSee('صندوق النور', false)
        ->assertDontSee('Al Noor Fund', false);
});

test('public header shows english fund name when locale is english', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_name_en' => 'Al Noor Fund',
        'fund_name_ar' => 'صندوق النور',
    ]);

    $this->get('http://' . $domain . '/locale/en')
        ->assertRedirect();

    $this->get('http://' . $domain)
        ->assertSuccessful()
        ->assertSee('tenant-public-nav__fund-name', false)
        ->assertSee('tenant-public-nav__language', false)
        ->assertSee('Al Noor Fund', false)
        ->assertDontSee('صندوق النور', false);
});

test('landing page defaults to arabic locale and rtl layout', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $response = $this->get('http://' . $domain);

    $response
        ->assertSuccessful()
        ->assertSee('dir="rtl"', false)
        ->assertSee('lang="ar"', false)
        ->assertSee(__('Member login', locale: 'ar'), false)
        ->assertSee(__('Building wealth', locale: 'ar'), false)
        ->assertSee(__('How it works', locale: 'ar'), false)
        ->assertDontSee('>How It Works<', false);
});

test('landing page can be switched to english via query locale', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://' . $domain . '?locale=en')
        ->assertSuccessful()
        ->assertSee('dir="ltr"', false)
        ->assertSee(__('Member login', locale: 'en'), false);
});

test('application locale configuration supports arabic and english', function () {
    expect(AppLocale::SUPPORTED)->toBe(['ar', 'en'])
        ->and(AppLocale::DEFAULT)->toBe('ar')
        ->and(AppLocale::htmlDir('ar'))->toBe('rtl')
        ->and(AppLocale::htmlDir('en'))->toBe('ltr');
});

test('public nav shows flag language switcher when locale is arabic', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://' . $domain)
        ->assertSuccessful()
        ->assertSee('language-switch-trigger', false)
        ->assertSee('flagcdn.com/w40/sa.png', false);
});

test('locale switch route sets session and redirects back', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://' . $domain . '/locale/en')
        ->assertRedirect()
        ->assertSessionHas('locale', 'en');

    $this->get('http://' . $domain)
        ->assertSuccessful()
        ->assertSee('dir="ltr"', false)
        ->assertSee('flagcdn.com/w40/gb.png', false);
});
