<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LegacyMigration\LegacyMigrationCashSupplementRepairService;
use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class LegacyRepairCashSupplementsCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'legacy:repair-cash-supplements';

    protected $description = 'Merge legacy migration cash supplements into their contribution periods';

    public function handle(LegacyMigrationCashSupplementRepairService $repair): int
    {
        $result = $repair->repairAll();

        $this->info(__('Merged :count legacy cash supplement(s) into contribution periods.', [
            'count' => $result['repaired'],
        ]));

        if ($result['skipped'] > 0) {
            $this->line(__('Skipped :count transaction(s).', ['count' => $result['skipped']]));
        }

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
