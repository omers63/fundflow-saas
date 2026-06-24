<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

/**
 * Runs an Artisan command once per tenant database (for cron / schedule:run).
 */
trait TenantAwareScheduledCommand
{
    use HasATenantsOption;
    use TenantAwareCommand;
}
