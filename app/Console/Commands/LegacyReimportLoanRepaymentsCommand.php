<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LegacyMigration\LegacyLoanRepaymentReimportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class LegacyReimportLoanRepaymentsCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'legacy:reimport-loan-repayments
        {--payments= : Absolute path to raw or classified payments CSV}
        {--members= : Optional members CSV for classification}
        {--loans= : Optional loans CSV for classification}
        {--classified= : Output path for classified CSV}
        {--cutoff= : Migration cut-off date (YYYY-MM-DD)}
        {--skip-reset : Do not clear existing imported repayments before import}';

    protected $description = 'Reset legacy loan repayments, re-classify payments with loan IDs, import, and sync schedules';

    public function handle(LegacyLoanRepaymentReimportService $service): int
    {
        $paymentsPath = (string) ($this->option('payments') ?? '');

        if ($paymentsPath === '' || ! is_readable($paymentsPath)) {
            $this->error('--payments must point to a readable CSV file.');

            return self::FAILURE;
        }

        if (! $this->option('skip-reset')) {
            $this->info(__('Resetting previously imported loan repayments…'));
            $reset = $service->resetImportedLoanRepayments();
            $this->table(
                ['Metric', 'Count'],
                collect($reset)->map(fn (int $count, string $key) => [str_replace('_', ' ', $key), $count])->all(),
            );
        }

        $classifiedPath = (string) ($this->option('classified') ?? '');
        if ($classifiedPath === '') {
            $classifiedPath = dirname($paymentsPath).'/reclassified-payments.csv';
        }

        $cutoff = filled($this->option('cutoff'))
            ? Carbon::parse((string) $this->option('cutoff'))
            : null;

        $membersPath = $this->option('members');
        $loansPath = $this->option('loans');

        $this->info(__('Re-classifying and importing payments…'));

        $result = $service->reclassifyAndImport(
            $paymentsPath,
            $classifiedPath,
            is_string($membersPath) && $membersPath !== '' ? $membersPath : null,
            is_string($loansPath) && $loansPath !== '' ? $loansPath : null,
            $cutoff,
        );

        $this->info(__('Classification: :contributions contributions, :loan_repayments loan repayments, :ignored ignored, :failed failed.', [
            'contributions' => $result['classification']['contribution'],
            'loan_repayments' => $result['classification']['loan_repayment'],
            'ignored' => $result['classification']['ignore'],
            'failed' => $result['classification']['failed'],
        ]));

        $this->info(__('Import: :contributions contributions, :loan_repayments loan repayments, :ignored ignored, :failed failed.', [
            'contributions' => $result['import']['contributions'],
            'loan_repayments' => $result['import']['loan_repayments'],
            'ignored' => $result['import']['ignored'],
            'failed' => $result['import']['failed'],
        ]));

        $this->info(__('Schedule sync: :loans loans, :installments installments marked paid.', [
            'loans' => $result['schedule']['loans'],
            'installments' => $result['schedule']['installments'],
        ]));

        $this->line(__('Classified CSV written to :path', ['path' => $result['classified_path']]));

        if ($result['import']['failed'] > 0) {
            foreach (array_slice($result['import']['errors'], 0, 10) as $error) {
                $this->warn($error);
            }
        }

        return self::SUCCESS;
    }
}
