<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresBatchPostingAllowed;
use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ContributionCycleService;
use App\Support\BusinessDay;
use Illuminate\Console\Command;

class ApplyMonthlyContributions extends Command
{
    use EnsuresBatchPostingAllowed;
    use TenantAwareScheduledCommand;

    protected $signature = 'contributions:apply {--month=} {--year=}';

    protected $description = 'Apply cycle contributions for all eligible members';

    public function handle(ContributionCycleService $cycles): int
    {
        if (! $this->ensureBatchPostingAllowed()) {
            return self::SUCCESS;
        }
        $month = $this->option('month') ? (int) $this->option('month') : null;
        $year = $this->option('year') ? (int) $this->option('year') : null;

        if ($month === null || $year === null) {
            $previous = BusinessDay::now()->subMonthNoOverflow();
            $month = (int) $previous->month;
            $year = (int) $previous->year;
        }

        $results = $cycles->applyContributions($month, $year);

        $this->info(__('Applied: :applied | Insufficient: :insufficient | Skipped: :skipped', [
            'applied' => $results['applied']->count(),
            'insufficient' => $results['insufficient']->count(),
            'skipped' => $results['skipped']->count(),
        ]));

        return self::SUCCESS;
    }
}
