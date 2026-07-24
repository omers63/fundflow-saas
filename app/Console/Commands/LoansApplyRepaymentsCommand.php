<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresBatchPostingAllowed;
use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Loans\LoanRepaymentService;
use App\Support\AutomationScheduleSettings;
use Illuminate\Console\Command;

class LoansApplyRepaymentsCommand extends Command
{
    use EnsuresBatchPostingAllowed;
    use TenantAwareScheduledCommand;

    protected $signature = 'loans:apply-repayments {--month=} {--year=} {--force : Run even when not in a configured apply slot}';

    protected $description = 'Apply scheduled loan repayments for the given or current open period';

    public function handle(
        LoanRepaymentService $repayments,
        ContributionCycleService $cycle,
        LoanDelinquencyService $delinquency,
    ): int {
        if (! $this->ensureBatchPostingAllowed()) {
            return self::SUCCESS;
        }

        $forcedPeriod = $this->option('month') && $this->option('year');

        if (! $this->option('force') && ! AutomationScheduleSettings::autoApplyCollections()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: auto-apply allocations, contributions, and EMI repayments is disabled in Settings.'));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $forcedPeriod && ! AutomationScheduleSettings::isLoanApplySlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: not a configured loan repayment apply slot (:times).', [
                'times' => implode(', ', AutomationScheduleSettings::loanApplyTimes()),
            ]));

            return self::SUCCESS;
        }

        if ($forcedPeriod) {
            $month = (int) $this->option('month');
            $year = (int) $this->option('year');
        } else {
            [$month, $year] = $cycle->currentOpenPeriod();
        }

        $results = $repayments->applyRepayments($month, $year, collectOldestArrearsFirst: true);

        $this->info(__('Period :period — applied: :applied, insufficient: :insufficient, skipped: :skipped', [
            'period' => $cycle->periodLabel($month, $year),
            'applied' => $results['applied']->count(),
            'insufficient' => $results['insufficient']->count(),
            'skipped' => $results['skipped']->count(),
        ]));

        if (AutomationScheduleSettings::loanDefaultsEnabled()) {
            $delinquencyResult = $delinquency->runDailyMaintenance();

            $this->info(__('Delinquency after apply — overdue: :overdue, delinquent: :delinquent, transfers: :transferred', [
                'overdue' => $delinquencyResult['marked_overdue'],
                'delinquent' => $delinquencyResult['delinquent_count'],
                'transferred' => $delinquencyResult['transferred_to_guarantor'] ?? 0,
            ]));
        }

        return self::SUCCESS;
    }
}
