<?php

use App\Models\Central\Tenant;

test('tenant landing page returns successful response', function () {
    $tenant = Tenant::find('testing');

    $domain = 'testing.localhost';
    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $response = $this->get("http://{$domain}");

    $response->assertStatus(200);
    $response->assertSee('Building Wealth');
    $response->assertSee('Together');
});
