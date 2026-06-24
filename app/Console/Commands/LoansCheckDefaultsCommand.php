<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\Loans\LoanDelinquencyService;
use Illuminate\Console\Command;

class LoansCheckDefaultsCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'loans:check-defaults';

    protected $description = 'Mark overdue installments, sync member delinquency, and process guarantor defaults';

    public function handle(LoanDelinquencyService $delinquency): int
    {
        $result = $delinquency->runDailyMaintenance();

        $this->info(__('Overdue marked: :overdue, delinquent members: :delinquent, restored: :restored, warnings: :warned, guarantor debits: :debited, auto-transfers: :transferred', [
            'overdue' => $result['marked_overdue'],
            'delinquent' => $result['marked_delinquent'],
            'restored' => $result['restored_active'],
            'warned' => $result['warned'],
            'debited' => $result['debited_from_guarantor'],
            'transferred' => $result['transferred_to_guarantor'] ?? 0,
        ]));

        return self::SUCCESS;
    }
}
