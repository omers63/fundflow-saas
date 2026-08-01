<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresBatchPostingAllowed;
use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ContributionCycleService;
use App\Services\LoanInstallmentCollectionCycleService;
use App\Support\AutomationScheduleSettings;
use Illuminate\Console\Command;

class LoansCloseEmiWindowCommand extends Command
{
    use EnsuresBatchPostingAllowed;
    use TenantAwareScheduledCommand;

    protected $signature = 'loans:close-emi-window {--month=} {--year=} {--force : Run even when not in the configured EMI close slot}';

    protected $description = 'Close the prior EMI collection window and mark unpaid installments overdue';

    public function handle(
        LoanInstallmentCollectionCycleService $emiCycles,
        ContributionCycleService $cycles,
    ): int {
        if (! $this->ensureBatchPostingAllowed()) {
            return self::SUCCESS;
        }

        if ($this->shouldSkipUntilCycleTransition($cycles)) {
            return self::SUCCESS;
        }

        [$month, $year] = $this->resolvePeriod($cycles);
        $flagged = $emiCycles->closeCollectionWindow($month, $year);

        $this->info(__('Flagged :count EMI(s) overdue for :period.', [
            'count' => $flagged,
            'period' => $cycles->periodLabel($month, $year),
        ]));

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function resolvePeriod(ContributionCycleService $cycles): array
    {
        if ($this->option('month') && $this->option('year')) {
            return [(int) $this->option('month'), (int) $this->option('year')];
        }

        // On cycle start day, close the period that just ended — never the newly opened cycle.
        return $cycles->periodClosedByTransition();
    }

    protected function shouldSkipUntilCycleTransition(ContributionCycleService $cycles): bool
    {
        if ($this->option('month') && $this->option('year')) {
            return false;
        }

        if ($this->option('force')) {
            return false;
        }

        if (! $cycles->isCycleTransitionDay()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: today is not the contribution cycle start day (:day).', [
                'day' => $cycles->cycleStartDay(),
            ]));

            return true;
        }

        if (! AutomationScheduleSettings::isEmiCloseSlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: not the configured EMI close-window time (:time).', [
                'time' => AutomationScheduleSettings::emiCloseTime(),
            ]));

            return true;
        }

        return false;
    }
}
