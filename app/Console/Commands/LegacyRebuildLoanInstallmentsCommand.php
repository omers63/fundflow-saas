<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LegacyMigration\LegacyImportedLoanInstallmentRebuildService;
use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class LegacyRebuildLoanInstallmentsCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'legacy:rebuild-loan-installments
        {--loan= : Rebuild a single loan by ID}';

    protected $description = 'Rebuild legacy-imported loan installment schedules using the 50/50 + settlement repayment formula';

    public function handle(LegacyImportedLoanInstallmentRebuildService $rebuild): int
    {
        $loanId = $this->option('loan');
        $loanFilter = is_string($loanId) && $loanId !== '' ? (int) $loanId : null;

        $result = $rebuild->rebuildImplicitPortionLoans($loanFilter);

        $this->info(__('Rebuilt :loans loan(s); created :installments installment row(s).', [
            'loans' => $result['loans'],
            'installments' => $result['installments'],
        ]));

        return self::SUCCESS;
    }
}
