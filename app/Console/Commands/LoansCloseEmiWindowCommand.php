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

    protected $description = 'Close the EMI collection window and mark unpaid installments overdue';

    public function handle(
        LoanInstallmentCollectionCycleService $emiCycles,
        ContributionCycleService $cycles,
    ): int {
        if (! $this->ensureBatchPostingAllowed()) {
            return self::SUCCESS;
        }

        $forcedPeriod = $this->option('month') && $this->option('year');

        if (! $this->option('force') && ! $forcedPeriod && ! AutomationScheduleSettings::isEmiCloseSlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: EMI close runs on day :day at :time.', [
                'day' => AutomationScheduleSettings::emiCloseDay(),
                'time' => AutomationScheduleSettings::emiCloseTime(),
            ]));

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

        return $cycles->currentOpenPeriod();
    }
}
