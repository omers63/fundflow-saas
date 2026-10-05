<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Support\Billing\TenantFeatureGate;
use Illuminate\Console\Command;
use Stancl\Tenancy\Facades\Tenancy;

class MeterTenantUsageCommand extends Command
{
    protected $signature = 'billing:meter-usage {--tenant= : Tenant id}';

    protected $description = 'Meter active members (and stub API call counters) for SaaS plan enforcement.';

    public function handle(TenantFeatureGate $features): int
    {
        $tenantId = $this->option('tenant');

        $query = Tenant::query()->where('is_provisioned', true);
        if (is_string($tenantId) && $tenantId !== '') {
            $query->whereKey($tenantId);
        }

        $query->each(function (Tenant $tenant) use ($features): void {
            Tenancy::initialize($tenant);

            try {
                $activeMembers = $features->activeMemberCount();
                $maxMembers = $features->maxMembers();
                $overLimit = $maxMembers !== null && $activeMembers > $maxMembers;

                $this->line(sprintf(
                    '%s members=%d max=%s over_limit=%s features=%s',
                    $tenant->id,
                    $activeMembers,
                    $maxMembers ?? '∞',
                    $overLimit ? 'yes' : 'no',
                    implode(',', $features->features()),
                ));
            } finally {
                Tenancy::end();
            }
        });

        return self::SUCCESS;
    }
}
