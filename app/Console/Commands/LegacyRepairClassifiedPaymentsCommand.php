<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use App\Services\LegacyMigration\LegacyPaymentImportService;
use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class LegacyRepairClassifiedPaymentsCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'legacy:repair-classified-payments
        {--classified= : Absolute path to classified payments CSV (defaults to last-classified-payments.csv)}';

    protected $description = 'Downgrade unresolvable loan_repayment rows to contributions in the classified payments CSV';

    public function handle(LegacyPaymentImportService $importService): int
    {
        $classifiedPath = (string) ($this->option('classified') ?? '');

        if ($classifiedPath === '') {
            $classifiedPath = LegacyPaymentClassifierService::classifiedPaymentsAbsolutePath() ?? '';
        }

        if ($classifiedPath === '' || ! is_readable($classifiedPath)) {
            $this->error(__('Classified payments CSV not found. Pass --classified or run payment classification first.'));

            return self::FAILURE;
        }

        $reclassified = $importService->repairClassifiedFile($classifiedPath);

        $this->info(__('Reclassified :count loan repayment row(s) as contributions.', [
            'count' => $reclassified,
        ]));
        $this->line($classifiedPath);

        return self::SUCCESS;
    }
}
