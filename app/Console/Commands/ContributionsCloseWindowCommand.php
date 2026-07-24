<?php

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresBatchPostingAllowed;
use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ContributionCollectionCycleService;
use App\Services\ContributionCycleService;
use App\Support\AutomationScheduleSettings;
use Illuminate\Console\Command;

class ContributionsCloseWindowCommand extends Command
{
    use EnsuresBatchPostingAllowed;
    use TenantAwareScheduledCommand;

    protected $signature = 'contributions:close-window {--month=} {--year=} {--force : Run even when not on the configured cycle close slot}';

    protected $description = 'Close the collection window and flag overdue members';

    public function handle(ContributionCollectionCycleService $collection, ContributionCycleService $cycles): int
    {
        if (! $this->ensureBatchPostingAllowed()) {
            return self::SUCCESS;
        }

        if ($this->shouldSkipUntilCycleTransition($cycles)) {
            return self::SUCCESS;
        }

        [$month, $year] = $this->resolvePeriod($cycles);
        $flagged = $collection->closeCollectionWindow($month, $year);

        $this->info(__('Flagged :count member(s) overdue for :period.', [
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

        if (! AutomationScheduleSettings::isCycleCloseSlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: not the configured close-window time (:time).', [
                'time' => AutomationScheduleSettings::cycleCloseTime(),
            ]));

            return true;
        }

        return false;
    }
}
