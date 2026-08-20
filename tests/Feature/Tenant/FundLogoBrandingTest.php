<?php

use App\Models\Central\Tenant;
use App\Support\AppBrand;
use App\Support\BrandAppearanceSettings;
use App\Support\PublicPageContentSettings;
use App\Support\PublicPageSettings;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    config([
        'branding.path' => base_path('branding'),
        'branding.active' => 'fundflow',
        'branding.default' => 'fundflow',
    ]);
    AppBrand::flush();
    $this->initializeTenancy();
    Storage::fake('public');
    PublicPageSettings::save(PublicPageSettings::defaults());
    BrandAppearanceSettings::save(BrandAppearanceSettings::defaults());
    PublicPageContentSettings::save(PublicPageContentSettings::defaults());
});

test('tenant manifest uses the active brand pack icons when no custom icons are configured', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    config(['branding.active' => 'samman']);
    AppBrand::flush();
    BrandAppearanceSettings::save(BrandAppearanceSettings::defaults());

    $this->get('http://'.$domain.'/manifest.json')
        ->assertSuccessful()
        ->assertJsonPath('icons.0.src', fn (string $src): bool => str_contains($src, '/branding/samman/icons/icon-72x72.png'))
        ->assertJsonPath('icons.7.src', fn (string $src): bool => str_contains($src, '/branding/samman/icons/icon-512x512.png'));
});

test('tenant manifest uses default per-size PWA icons when no custom icons are configured', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain.'/manifest.json')
        ->assertSuccessful()
        ->assertJsonPath('theme_color', '#059669')
        ->assertJsonPath('background_color', '#059669')
        ->assertJsonPath('icons.0.src', fn (string $src): bool => str_contains($src, AppBrand::iconWebPath('pwa_72')))
        ->assertJsonPath('icons.0.sizes', '72x72')
        ->assertJsonPath('icons.5.purpose', 'any maskable')
        ->assertJsonPath('icons.7.src', fn (string $src): bool => str_contains($src, AppBrand::iconWebPath('pwa_512')));
});

test('tenant manifest uses fund name without substituting the fund logo for PWA icons', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    Storage::disk('public')->put('fund-branding/logo.png', 'logo');

    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_name_en' => 'Al Noor Fund',
        'fund_name_ar' => 'صندوق النور',
        'fund_logo' => 'fund-branding/logo.png',
    ]);

    app()->setLocale('en');

    $this->get('http://'.$domain.'/manifest.json')
        ->assertSuccessful()
        ->assertJsonPath('name', 'Al Noor Fund')
        ->assertJsonPath('icons.0.src', fn (string $src): bool => str_contains($src, AppBrand::iconWebPath('pwa_72')));
});

test('tenant manifest uses uploaded PWA icons and theme colors', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    Storage::disk('public')->put('fund-branding/icons/pwa-72.png', 'icon');

    BrandAppearanceSettings::save([
        ...BrandAppearanceSettings::defaults(),
        'theme_color' => '#111827',
        'background_color' => '#F8FAFC',
        'icon_pwa_72' => 'fund-branding/icons/pwa-72.png',
    ]);

    $this->get('http://'.$domain.'/manifest.json')
        ->assertSuccessful()
        ->assertJsonPath('theme_color', '#111827')
        ->assertJsonPath('background_color', '#F8FAFC')
        ->assertJsonPath('icons.0.src', fn (string $src): bool => str_contains($src, '/tenancy/assets/fund-branding/icons/pwa-72.png'));
});

test('landing page renders default FundFlow logo when no custom logo is configured', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    app()->setLocale('en');

    $this->get('http://'.$domain.'?locale=en')
        ->assertSuccessful()
        ->assertSee(AppBrand::logoWebPath(), false)
        ->assertSee('Trusted family fund management', false)
        ->assertSee('Building wealth', false);
});

test('landing page renders uploaded fund logo in navigation', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    Storage::disk('public')->put('fund-branding/logo.png', 'logo');

    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_logo' => 'fund-branding/logo.png',
    ]);

    $this->get('http://'.$domain.'?locale=en')
        ->assertSuccessful()
        ->assertSee('/tenancy/assets/fund-branding/logo.png', false);
});

test('landing page renders customized chrome and sidebar content', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    PublicPageContentSettings::saveFromForm([
        ...PublicPageContentSettings::allForForm(),
        'hero_badge_en' => 'Custom family fund',
        'nav_home_en' => 'Start',
        'sidebar_html_en' => '<p>Office hours</p>',
        'header_banner_en' => 'Welcome banner',
    ]);

    app()->setLocale('en');

    $this->get('http://'.$domain.'?locale=en')
        ->assertSuccessful()
        ->assertSee('Custom family fund', false)
        ->assertSee('Start', false)
        ->assertSee('Office hours', false)
        ->assertSee('Welcome banner', false);
});

test('public pages expose configured favicon and theme color', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    Storage::disk('public')->put('fund-branding/icons/favicon-32.png', 'icon');

    BrandAppearanceSettings::save([
        ...BrandAppearanceSettings::defaults(),
        'theme_color' => '#123456',
        'icon_favicon_32' => 'fund-branding/icons/favicon-32.png',
    ]);

    $this->get('http://'.$domain.'?locale=en')
        ->assertSuccessful()
        ->assertSee('content="#123456"', false)
        ->assertSee('/tenancy/assets/fund-branding/icons/favicon-32.png', false);
});
