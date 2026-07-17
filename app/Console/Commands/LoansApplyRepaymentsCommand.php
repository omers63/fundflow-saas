<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresBatchPostingAllowed;
use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanRepaymentService;
use Illuminate\Console\Command;

class LoansApplyRepaymentsCommand extends Command
{
    use EnsuresBatchPostingAllowed;
    use TenantAwareScheduledCommand;

    protected $signature = 'loans:apply-repayments {--month=} {--year=}';

    protected $description = 'Apply scheduled loan repayments for the given or current open period';

    public function handle(LoanRepaymentService $repayments, ContributionCycleService $cycle): int
    {
        if (! $this->ensureBatchPostingAllowed()) {
            return self::SUCCESS;
        }
        if ($this->option('month') && $this->option('year')) {
            $month = (int) $this->option('month');
            $year = (int) $this->option('year');
        } else {
            [$month, $year] = $cycle->currentOpenPeriod();
        }

        $results = $repayments->applyRepayments($month, $year);

        $this->info(__('Period :period — applied: :applied, insufficient: :insufficient, skipped: :skipped', [
            'period' => $cycle->periodLabel($month, $year),
            'applied' => $results['applied']->count(),
            'insufficient' => $results['insufficient']->count(),
            'skipped' => $results['skipped']->count(),
        ]));

        return self::SUCCESS;
    }
}
