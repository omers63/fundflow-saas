<?php

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresBatchPostingAllowed;
use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ContributionCollectionCycleService;
use App\Services\ContributionCycleService;
use Illuminate\Console\Command;

class ContributionsCloseWindowCommand extends Command
{
    use EnsuresBatchPostingAllowed;
    use TenantAwareScheduledCommand;

    protected $signature = 'contributions:close-window {--month=} {--year=}';

    protected $description = 'Close the collection window and flag overdue members';

    public function handle(ContributionCollectionCycleService $collection, ContributionCycleService $cycles): int
    {
        if (! $this->ensureBatchPostingAllowed()) {
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

        $open = $cycles->currentOpenPeriod();
        $start = $cycles->cycleStartAt($open[0], $open[1])->copy()->subMonthNoOverflow();

        return [(int) $start->month, (int) $start->year];
    }
}
