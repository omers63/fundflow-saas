<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountingService;
use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class AccountingRepairDuplicatePoolReversalMirrorsCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'accounting:repair-duplicate-pool-reversal-mirrors
        {--dry-run : List duplicate master reversal-mirrors without posting counter-entries}';

    protected $description = 'Reverse extra master cash/fund legs created when a full-source reverse also auto-mirrored member pool lines';

    public function handle(AccountingService $accounting): int
    {
        $duplicates = $accounting->duplicateMasterPoolReversalMirrors();

        if ($this->option('dry-run')) {
            $this->info(__('Duplicate master pool reversal-mirrors: :count', [
                'count' => $duplicates->count(),
            ]));

            foreach ($duplicates as $mirror) {
                $this->line(sprintf(
                    '#%d %s %s %s',
                    $mirror->id,
                    $mirror->type,
                    number_format((float) $mirror->amount, 2, '.', ''),
                    $mirror->description ?? '',
                ));
            }

            return self::SUCCESS;
        }

        $repaired = $accounting->repairDuplicateMasterPoolReversalMirrors();

        $this->info(__('Reversed duplicate master pool reversal-mirrors: :count', [
            'count' => $repaired,
        ]));

        return self::SUCCESS;
    }
}
