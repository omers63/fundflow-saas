<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LegacyMigration\LegacyMigrationZeroBalanceLoanCompletionService;
use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class LegacyCompleteZeroBalanceLoansCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'legacy:complete-zero-balance-loans';

    protected $description = 'Mark active or transferred loans with zero outstanding balance as completed';

    public function handle(LegacyMigrationZeroBalanceLoanCompletionService $completion): int
    {
        $result = $completion->completeAll();

        $this->info(__('Completed :count loan(s) with zero outstanding balance.', [
            'count' => $result['completed'],
        ]));

        if ($result['loan_ids'] !== []) {
            $this->line(implode(', ', array_map('strval', $result['loan_ids'])));
        }

        return self::SUCCESS;
    }
}
