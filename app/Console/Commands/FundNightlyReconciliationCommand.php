<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ReconciliationDigestService;
use App\Services\ReconciliationService;
use App\Support\AutomationScheduleSettings;
use Filament\Facades\Filament;
use Illuminate\Console\Command;

class FundNightlyReconciliationCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'fund:nightly-reconciliation
        {--force : Run even when not in the configured daily slot}';

    protected $description = 'Run nightly reconciliation batch (master invariants, domain checks, auto-resolve)';

    public function handle(ReconciliationService $reconciliation, ReconciliationDigestService $digest): int
    {
        if (! $this->option('force') && ! AutomationScheduleSettings::isNightlyReconcileSlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: nightly reconciliation runs at :time.', [
                'time' => AutomationScheduleSettings::nightlyReconcileTime(),
            ]));

            return self::SUCCESS;
        }

        Filament::setCurrentPanel('tenant');

        $result = $reconciliation->runNightlyBatch();

        $digest->notifyAdminsOfNightlyBatch($result);

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
