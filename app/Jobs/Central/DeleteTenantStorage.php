<?php

namespace App\Jobs\Central;

use App\Models\Central\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * This job is responsible for deleting all physical files belonging to a tenant.
 * Includes local storage folders and files in Cloud Storage (S3/R2/etc).
 */
class DeleteTenantStorage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tenant;

    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    public function handle(): void
    {
        // 1. Delete local cache/storage directory if it exists
        $localPath = storage_path("tenant{$this->tenant->id}");
        if (File::exists($localPath)) {
            File::deleteDirectory($localPath);
        }

        // 2. Delete tenant files from external storage (S3/MinIO/R2)
        // Ensure tenancy is initialized to access the correct tenant disk
        tenancy()->initialize($this->tenant);

        // Delete all files in the 'tenant' disk (usually the root disk path is already prefixed with tenant ID)
        Storage::disk('tenant')->deleteDirectory('');

        tenancy()->end();
    }
}
