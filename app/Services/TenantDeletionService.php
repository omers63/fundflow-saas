<?php

namespace App\Services;

use App\Jobs\Central\DeleteTenantRecord;
use App\Jobs\Central\DeleteTenantStorage;
use App\Models\Central\Tenant;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Jobs;

/**
 * This service handles the complete tenant deletion process.
 * Uses queues to delete the database and files to avoid blocking the request.
 */
class TenantDeletionService
{
    /**
     * Delete a tenant's associated resources.
     */
    public function deleteTenant(Tenant $tenant): void
    {
        DB::beginTransaction();

        try {
            // 1. Delete tenant's domains (since foreign keys usually exist here)
            $tenant->domains()->delete();

            // 2. Build the job chain to run sequentially
            $jobs = [];

            if ($tenant->is_provisioned) {
                // Delete physical files
                $jobs[] = new DeleteTenantStorage($tenant);
                // Delete tenant database
                $jobs[] = new Jobs\DeleteDatabase($tenant);
            }

            // Final step: Delete tenant record in Central DB
            $jobs[] = new DeleteTenantRecord($tenant->id);

            // Dispatch the job chain
            Bus::chain($jobs)->dispatch();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
