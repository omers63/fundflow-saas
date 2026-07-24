<?php

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\MasterAccountInvariantService;
use App\Support\AutomationScheduleSettings;
use Illuminate\Console\Command;

class FundAssertMasterInvariantsCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'fund:assert-master-invariants
        {--force : Run even when not in the configured daily slot}
        {--strict : Exit with failure when master pools are imbalanced (CI / manual gates)}';

    protected $description = 'Assert master fund/cash equals sum of member accounts';

    public function handle(MasterAccountInvariantService $invariants): int
    {
        if (! $this->option('force') && ! AutomationScheduleSettings::isMasterInvariantsSlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: not the configured master invariants slot (:time).', [
                'time' => AutomationScheduleSettings::masterInvariantsTime(),
            ]));

            return self::SUCCESS;
        }

        $result = $invariants->check();

        if ($result['balanced']) {
            $this->info(__('Master accounts are balanced.'));

            return self::SUCCESS;
        }

        $this->warn(__('MASTER_IMBALANCE: fund delta :fund, cash delta :cash', [
            'fund' => number_format($result['fund_delta'], 2),
            'cash' => number_format($result['cash_delta'], 2),
        ]));

        // Scheduled runs must exit 0 once the check completed — imbalance is reported via
        // console output and recon digests, not as a scheduler failure / log ERROR.
        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
