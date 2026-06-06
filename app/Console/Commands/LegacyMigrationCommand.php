<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LegacyMigration\LegacyMigrationOrchestrator;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class LegacyMigrationCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'legacy:migrate
        {--members= : Absolute path to members CSV}
        {--loans= : Absolute path to loans CSV}
        {--payments= : Absolute path to classified payments CSV}
        {--cutoff= : Migration cut-off date (YYYY-MM-DD)}
        {--password= : Default member password (min 8 chars)}
        {--strategy=snapshot : snapshot or historical}
        {--dry-run : Validate and count rows without writing}
        {--classify-payments= : Classify ambiguous payments CSV and write output path}';

    protected $description = 'Import legacy members, loans, and optional payments from CSV files';

    public function handle(
        LegacyMigrationOrchestrator $orchestrator,
        LegacyPaymentClassifierService $classifier,
    ): int {
        $classifyOutput = $this->option('classify-payments');

        if (is_string($classifyOutput) && $classifyOutput !== '') {
            return $this->classifyPayments($classifier, $classifyOutput);
        }

        $members = (string) ($this->option('members') ?? '');
        $password = (string) ($this->option('password') ?? '');

        if ($members === '' || !is_readable($members)) {
            $this->error('--members must point to a readable CSV file.');

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('--password must be at least 8 characters.');

            return self::FAILURE;
        }

        $strategy = (string) $this->option('strategy');
        if (!in_array($strategy, ['snapshot', 'historical'], true)) {
            $this->error('--strategy must be snapshot or historical.');

            return self::FAILURE;
        }

        try {
            $result = $orchestrator->run([
                'cutoff_date' => $this->option('cutoff'),
                'default_password' => $password,
                'members_path' => $members,
                'loans_path' => $this->option('loans'),
                'payments_path' => $this->option('payments'),
                'strategy' => $strategy,
            ], (bool) $this->option('dry-run'));

            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function classifyPayments(LegacyPaymentClassifierService $classifier, string $outputPath): int
    {
        $source = (string) ($this->option('payments') ?? '');

        if ($source === '' || !is_readable($source)) {
            $this->error('Provide --payments=source.csv with --classify-payments=output.csv');

            return self::FAILURE;
        }

        $cutoff = filled($this->option('cutoff'))
            ? Carbon::parse((string) $this->option('cutoff'))
            : null;

        $result = $classifier->classifyFile($source, $cutoff);
        $classifier->writeClassifiedCsv($outputPath, $result['rows']);

        $this->info("Classified {$result['stats']['contribution']} contributions, {$result['stats']['loan_repayment']} loan repayments.");
        $this->info("Unclassified: {$result['stats']['unclassified']}. Written to {$outputPath}");

        return self::SUCCESS;
    }
}
