<?php

use App\Models\Central\Tenant;
use App\Support\PublicPageSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    PublicPageSettings::save(PublicPageSettings::defaults());
});

test('landing page shows download terms and conditions button when default pdf is available', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain)
        ->assertSuccessful()
        ->assertSee('Download Terms', false)
        ->assertSee('downloads/terms-and-conditions', false);
});

test('terms and conditions download route serves the default pdf', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain.'/downloads/terms-and-conditions')
        ->assertSuccessful()
        ->assertDownload('fund-terms-and-conditions.pdf');
});

test('custom rules url overrides the built-in download route', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'rules_and_conditions_url' => 'https://example.com/terms.pdf',
    ]);

    expect(PublicPageSettings::termsAndConditionsDownloadUrl())->toBe('https://example.com/terms.pdf');
});
