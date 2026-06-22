<?php

use App\Support\TenantAbsoluteUrl;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

test('tenant absolute url rewrites central app host to tenant domain', function () {
    $tenant = $this->initializeTenancy();
    $domain = $tenant->domains()->first()?->domain ?? 'testing.localhost';

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    config([
        'app.url' => 'https://fundflow-saas.osamman.com',
        'tenancy.central_domains' => ['fundflow-saas.osamman.com', '127.0.0.1', 'localhost'],
        'tenancy.central_domain' => 'fundflow-saas.osamman.com',
    ]);

    $resolved = TenantAbsoluteUrl::resolve('https://fundflow-saas.osamman.com/admin/loans/loans/42/edit');

    expect($resolved)->toBe("https://{$domain}/admin/loans/loans/42/edit");
});

test('tenant absolute url resolves relative filament paths on tenant domain', function () {
    $tenant = $this->initializeTenancy();
    $domain = $tenant->domains()->first()?->domain ?? 'testing.localhost';

    config([
        'app.url' => 'https://fundflow-saas.osamman.com',
        'tenancy.central_domains' => ['fundflow-saas.osamman.com'],
    ]);

    $resolved = TenantAbsoluteUrl::resolve('/admin/loans/loans/7/edit');

    expect($resolved)->toBe("https://{$domain}/admin/loans/loans/7/edit");
});
