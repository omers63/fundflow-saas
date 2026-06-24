<?php

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresBatchPostingAllowed;
use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ContributionCollectionCycleService;
use App\Services\Loans\LoanInstallmentLateFeeService;
use Illuminate\Console\Command;

class ContributionsApplyLateFeesCommand extends Command
{
    use EnsuresBatchPostingAllowed;
    use TenantAwareScheduledCommand;

    protected $signature = 'contributions:apply-late-fees';

    protected $description = 'Apply tiered late fees to overdue contribution cycles';

    public function handle(ContributionCollectionCycleService $collection): int
    {
        if ($this->ensureBatchPostingAllowed() !== self::SUCCESS) {
            return self::FAILURE;
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
