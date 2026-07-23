<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresBatchPostingAllowed;
use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ContributionCollectionCycleService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanInstallmentLateFeeService;
use App\Support\AutomationScheduleSettings;
use Illuminate\Console\Command;

class ApplyMonthlyContributions extends Command
{
    use EnsuresBatchPostingAllowed;
    use TenantAwareScheduledCommand;

    protected $signature = 'contributions:apply {--month=} {--year=} {--force : Run even when not in a configured apply slot}';

    protected $description = 'Apply cycle contributions for all eligible members';

    public function handle(ContributionCycleService $cycles, ContributionCollectionCycleService $collection): int
    {
        if (! $this->ensureBatchPostingAllowed()) {
            return self::SUCCESS;
        }

        $forcedPeriod = $this->option('month') && $this->option('year');

        if (! $this->option('force') && ! AutomationScheduleSettings::autoApplyCollections()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: auto-apply allocations, contributions, and EMI repayments is disabled in Settings.'));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $forcedPeriod && ! AutomationScheduleSettings::isContributionApplySlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: not a configured contribution apply slot (:times).', [
                'times' => implode(', ', AutomationScheduleSettings::contributionApplyTimes()),
            ]));

            return self::SUCCESS;
        }

        if ($forcedPeriod) {
            $month = (int) $this->option('month');
            $year = (int) $this->option('year');
        } else {
            [$month, $year] = $cycles->currentOpenPeriod();
        }

        $results = $cycles->applyContributions($month, $year, collectOldestArrearsFirst: true);

        $this->info(__('Applied: :applied | Insufficient: :insufficient | Skipped: :skipped', [
            'applied' => $results['applied']->count(),
            'insufficient' => $results['insufficient']->count(),
            'skipped' => $results['skipped']->count(),
        ]));

        $contributionFees = $collection->applyNightlyLateFees();
        $installmentFees = app(LoanInstallmentLateFeeService::class)->applyNightlyLateFees();

        $this->info(__('Late fees after apply — contributions: :c, EMIs: :e', [
            'c' => $contributionFees,
            'e' => $installmentFees,
        ]));

        return self::SUCCESS;
    }
}
