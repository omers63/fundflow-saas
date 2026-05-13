<?php

use App\Models\Central\Tenant;

test('tenant application returns successful response', function () {
    // Use the existing 'testing' tenant which has a database created
    $tenant = Tenant::find('testing');

    // Create a domain for the testing tenant if not exists
    $domain = 'testing.localhost';
    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $response = $this->get("http://{$domain}");

    $response->assertStatus(200);
    $response->assertSee('This is your multi-tenant application');
    $response->assertSee('testing');
});
