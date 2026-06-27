<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LegacyMigration\LegacyLoanDisbursementPortionRepairService;
use App\Services\LegacyMigration\LegacyMigrationWorkingCopy;
use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class LegacyRepairLoanDisbursementPortionsCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'legacy:repair-loan-disbursement-portions
        {--payments= : Absolute path to raw payments CSV (defaults to working copy)}';

    protected $description = 'Recalculate legacy loan member/master disbursement portions from historical payments and repair records';

    public function handle(
        LegacyLoanDisbursementPortionRepairService $repair,
        LegacyMigrationWorkingCopy $workingCopy,
    ): int {
        $paymentsPath = $this->option('payments');

        if (! is_string($paymentsPath) || $paymentsPath === '') {
            $paymentsPath = $workingCopy->existingPaths()['payments_path'] ?? null;
        }

        if (! is_string($paymentsPath) || ! is_readable($paymentsPath)) {
            $this->error(__('Payments CSV is required. Upload it to the migration workspace or pass --payments=.'));

            return self::FAILURE;
        }

        $result = $repair->repairFromPaymentsCsv($paymentsPath);

        $this->info(__('Scanned :scanned loan(s); repaired :repaired.', [
            'scanned' => $result['scanned'],
            'repaired' => $result['repaired'],
        ]));

        if ($result['loan_ids'] !== []) {
            $this->line(implode(', ', array_map('strval', $result['loan_ids'])));
        }

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
