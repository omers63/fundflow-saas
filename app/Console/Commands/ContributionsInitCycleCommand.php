<?php

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresBatchPostingAllowed;
use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ContributionCollectionCycleService;
use App\Services\ContributionCycleService;
use Illuminate\Console\Command;

class ContributionsInitCycleCommand extends Command
{
    use EnsuresBatchPostingAllowed;
    use TenantAwareScheduledCommand;

    protected $signature = 'contributions:init-cycle {--month=} {--year=}';

    protected $description = 'Initialize the open contribution cycle (pending ledger + balance snapshots)';

    public function handle(ContributionCollectionCycleService $collection, ContributionCycleService $cycles): int
    {
        if (! $this->ensureBatchPostingAllowed()) {
            return self::SUCCESS;
        }
        [$month, $year] = $this->resolvePeriod($cycles);
        $created = $collection->initializeOpenPeriod($month, $year);

        $this->info(__('Initialized :count contribution(s) for :period.', [
            'count' => $created,
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
