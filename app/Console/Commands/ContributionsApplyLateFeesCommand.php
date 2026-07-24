<?php

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresBatchPostingAllowed;
use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ContributionCollectionCycleService;
use App\Services\Loans\LoanInstallmentLateFeeService;
use App\Support\AutomationScheduleSettings;
use Illuminate\Console\Command;

class ContributionsApplyLateFeesCommand extends Command
{
    use EnsuresBatchPostingAllowed;
    use TenantAwareScheduledCommand;

    protected $signature = 'contributions:apply-late-fees
        {--force : Run even when not in the configured late-fees slot}';

    protected $description = 'Apply tiered late fees to overdue contribution cycles';

    public function handle(ContributionCollectionCycleService $collection): int
    {
        if (! $this->ensureBatchPostingAllowed()) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! AutomationScheduleSettings::isLateFeesSlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: late fees run at :time when enabled.', [
                'time' => AutomationScheduleSettings::lateFeesTime(),
            ]));

            return self::SUCCESS;
        }

        if (! AutomationScheduleSettings::lateFeesEnabled() && ! $this->option('force')) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: late fee automation is disabled in settings.'));

            return self::SUCCESS;
        }

        $contributions = $collection->applyNightlyLateFees();
        $installments = app(LoanInstallmentLateFeeService::class)->applyNightlyLateFees();

        $this->info(__('Updated late fee tiers — contributions: :c, EMIs: :e', [
            'c' => $contributions,
            'e' => $installments,
        ]));

        return self::SUCCESS;
    }
}
