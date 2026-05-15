<?php

use App\Models\Central\Tenant;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

test('tenant public disk urls use tenancy assets route', function () {
    $this->initializeTenancy();

    $url = Storage::disk('public')->url('applications/example.pdf');

    expect($url)->toContain('/tenancy/assets/applications/example.pdf')
        ->not->toContain('/storage/applications/');
});

test('legacy storage path redirects to tenancy assets', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain.'/storage/applications/example.pdf')
        ->assertRedirectContains('/tenancy/assets/applications/example.pdf');
});
