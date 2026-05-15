<?php

namespace App\Services;

use App\Jobs\Central\CreateFrameworkCacheDirForTenant;
use App\Jobs\Central\MarkTenantAsProvisioned;
use App\Models\Central\Invoice;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Support\Facades\Bus;
use Stancl\Tenancy\Jobs;

/**
 * This service handles the 'Provisioning' process or setting up the tenant infrastructure.
 * Usually called after the payment invoice is confirmed as paid.
 */
class TenantProvisioningService
{
    /**
     * Provision a tenant after invoice payment.
     */
    public function provisionTenant(Invoice $invoice): void
    {
        $tenant = Tenant::find($invoice->tenant_id);

        if (! $tenant) {
            \Log::error('Tenant not found for invoice: '.$invoice->id);
            throw new \Exception('Tenant not found');
        }

        $this->provision($tenant);
    }

    /**
     * Provision a tenant manually (e.g. from Admin Panel).
     */
    public function provisionManual(Tenant $tenant): void
    {
        $this->provision($tenant);
    }

    /**
     * Internal logic for provisioning.
     */
    protected function provision(Tenant $tenant): void
    {
        try {
            // 1. Create domain for the tenant if it doesn't exist
            // Using the tenant ID as subdomain if domain_name is not explicitly set in 'data'
            $domain = $tenant->domain_name ?? $tenant->id.'.'.config('tenancy.central_domain');

            if (! $tenant->domains()->where('domain', $domain)->exists()) {
                $tenant->domains()->create([
                    'domain' => $domain,
                ]);
            }

            // 2. Run the database creation and migration job chain sequentially
            Bus::chain([
                new Jobs\CreateDatabase($tenant),      // Create new DB
                new Jobs\MigrateDatabase($tenant),     // Run migrations in database/migrations/tenant folder
                new CreateFrameworkCacheDirForTenant($tenant), // Setup isolated cache folder

                // Place additional jobs here (like seeding initial data, role setup, etc.)

                new MarkTenantAsProvisioned($tenant->id), // Mark as finished
            ])->dispatch();

            // 3. Activate the subscription
            $subscription = Subscription::where('tenant_id', $tenant->id)
                ->where('status', 'pending')
                ->first();

            if ($subscription) {
                $subscription->update(['status' => 'active']);
            }

        } catch (\Exception $e) {
            \Log::error('Provisioning failed for tenant '.$tenant->id.': '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate if the tenant is ready to be provisioned via Invoice.
     */
    public function canProvisionTenant(Invoice $invoice): bool
    {
        if ($invoice->status !== 'paid') {
            return false;
        }

        $tenant = Tenant::find($invoice->tenant_id);

        if (! $tenant || $tenant->is_provisioned) {
            return false;
        }

        return true;
    }
}
