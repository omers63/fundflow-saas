<?php

use App\Models\Central\Tenant;
use App\Support\PublicPageSettings;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Storage::fake('public');
    PublicPageSettings::save(PublicPageSettings::defaults());
});

test('tenant manifest uses default FundFlow logo when no custom logo is configured', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain.'/manifest.json')
        ->assertSuccessful()
        ->assertJsonPath('icons.0.src', PublicPageSettings::fundLogoUrl());
});

test('tenant manifest uses fund name and logo when configured', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    Storage::disk('public')->put('fund-branding/logo.png', 'logo');

    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_name' => 'Al Noor Fund',
        'fund_logo' => 'fund-branding/logo.png',
    ]);

    $this->get('http://'.$domain.'/manifest.json')
        ->assertSuccessful()
        ->assertJsonPath('name', 'Al Noor Fund')
        ->assertJsonPath('icons.0.src', fn (string $src): bool => str_contains($src, '/tenancy/assets/fund-branding/logo.png'));
});

test('landing page renders default FundFlow logo when no custom logo is configured', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain)
        ->assertSuccessful()
        ->assertSee('favicon-192x192.png', false);
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

    $this->get('http://'.$domain)
        ->assertSuccessful()
        ->assertSee('/tenancy/assets/fund-branding/logo.png', false);
});
