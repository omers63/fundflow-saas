<?php

namespace App\Jobs\Central;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * This job creates a framework cache directory specifically for each tenant.
 * Essential for file system isolation when using different storage paths per tenant.
 */
class CreateFrameworkCacheDirForTenant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $tenant;

    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    public function handle()
    {
        $this->tenant->run(function ($tenant) {
            $storage_path = storage_path("tenant{$tenant->id}");

            // Ensure the framework/cache folder structure exists so Laravel doesn't error when writing cache
            if (! file_exists("$storage_path/framework/cache")) {
                mkdir("$storage_path/framework/cache", 0777, true);
            }
        });
    }
}
