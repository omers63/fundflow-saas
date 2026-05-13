<?php

namespace App\Jobs\Central;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * This job deletes the tenant record from the 'tenants' table in the Central database.
 * Usually executed as the final step after the tenant DB and storage have been deleted.
 */
class DeleteTenantRecord implements ShouldQueue
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
        DB::table('tenants')->where('id', $this->tenantId)->delete();
    }
}
