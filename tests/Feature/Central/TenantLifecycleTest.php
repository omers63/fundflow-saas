<?php

use App\Jobs\Central\CreateFrameworkCacheDirForTenant;
use App\Jobs\Central\DeleteTenantRecord;
use App\Jobs\Central\DeleteTenantStorage;
use App\Jobs\Central\MarkTenantAsProvisioned;
use App\Models\Central\Invoice;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use App\Services\TenantDeletionService;
use App\Services\TenantProvisioningService;
use Illuminate\Support\Facades\Bus;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\DeleteDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;

beforeEach(function () {
    // Clear any existing bus fakes if any
});

test('tenant provisioning service dispatches correct jobs when invoice is paid', function () {
    Bus::fake();

    $tenant = Tenant::factory()->createQuietly(['id' => 'provision-test']);
    $plan = Plan::factory()->create();
    $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'pending']);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    $service = new TenantProvisioningService;
    $service->provisionTenant($invoice);

    // Assert domain was created
    expect($tenant->domains()->count())->toBe(1);
    expect($tenant->domains()->first()->domain)->toBe('provision-test.'.config('tenancy.central_domain'));

    // Assert Jobs were chained
    Bus::assertChained([
        CreateDatabase::class,
        MigrateDatabase::class,
        SeedDatabase::class,
        CreateFrameworkCacheDirForTenant::class,
        MarkTenantAsProvisioned::class,
    ]);

    expect($subscription->fresh()->status)->toBe('active');
});

test('tenant deletion service dispatches correct jobs and deletes domain', function () {
    Bus::fake();

    $tenant = Tenant::factory()->createQuietly(['id' => 'delete-test', 'is_provisioned' => true]);
    $tenant->domains()->create(['domain' => 'delete-test.local']);

    $service = new TenantDeletionService;
    $service->deleteTenant($tenant);

    // Assert domain was deleted
    expect($tenant->domains()->count())->toBe(0);

    // Assert Jobs were chained
    Bus::assertChained([
        DeleteTenantStorage::class,
        DeleteDatabase::class,
        DeleteTenantRecord::class,
    ]);
});
