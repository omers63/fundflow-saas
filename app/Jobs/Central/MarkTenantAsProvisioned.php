<?php

namespace App\Jobs\Central;

use App\Models\Central\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * This job marks the tenant as 'is_provisioned' after all setup processes (DB, Migration, Files) are complete.
 */
class MarkTenantAsProvisioned implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $tenantId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (! $tenant) {
            return; // Skip if tenant is not found (e.g., already deleted)
        }

        $tenant->update([
            'is_provisioned' => true,
            'provisioned_at' => now(),
        ]);
    }
}
