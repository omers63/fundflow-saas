<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ReconciliationService;
use Illuminate\Console\Command;

class FundNightlyReconciliationCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'fund:nightly-reconciliation';

    protected $description = 'Run nightly reconciliation batch (master invariants, domain checks, auto-resolve)';

    public function handle(ReconciliationService $reconciliation): int
    {
        $result = $reconciliation->runNightlyBatch();

        if ($result['halted']) {
            $this->error(__('Reconciliation halted: critical master imbalance.'));

            return self::FAILURE;
        }

        $this->info(__('Reconciliation complete. Raised: :raised, Resolved: :resolved, Critical: :critical', [
            'raised' => $result['raised'],
            'resolved' => $result['resolved'],
            'critical' => $result['critical'],
        ]));

        return self::SUCCESS;
    }
}
