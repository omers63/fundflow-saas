<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LegacyMigration\LegacyImportedLoanScheduleSyncService;
use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class LegacySyncLoanSchedulesCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'legacy:sync-loan-schedules
        {--loan= : Sync a single loan by ID}';

    protected $description = 'Mark imported loan repayments against installment schedules and complete fully repaid loans';

    public function handle(LegacyImportedLoanScheduleSyncService $sync): int
    {
        $loanId = $this->option('loan');

        if (is_string($loanId) && $loanId !== '') {
            $result = $sync->syncLoans([(int) $loanId]);
        } else {
            $result = $sync->syncAllLoansWithImportedRepayments();
        }

        $this->info(__('Synced :loans loan(s); marked :installments installment(s) paid.', [
            'loans' => $result['loans'],
            'installments' => $result['installments'],
        ]));

        return self::SUCCESS;
    }
}
