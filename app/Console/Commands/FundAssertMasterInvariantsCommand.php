<?php

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\MasterAccountInvariantService;
use Illuminate\Console\Command;

class FundAssertMasterInvariantsCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'fund:assert-master-invariants';

    protected $description = 'Assert master fund/cash equals sum of member accounts';

    public function handle(MasterAccountInvariantService $invariants): int
    {
        $result = $invariants->check();

        if ($result['balanced']) {
            $this->info(__('Master accounts are balanced.'));

            return self::SUCCESS;
        }

        $this->error(__('MASTER_IMBALANCE: fund delta :fund, cash delta :cash', [
            'fund' => number_format($result['fund_delta'], 2),
            'cash' => number_format($result['cash_delta'], 2),
        ]));

        return self::FAILURE;
    }
}
