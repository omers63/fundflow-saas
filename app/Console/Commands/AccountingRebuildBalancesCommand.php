<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant\Account;
use App\Services\AccountingService;
use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class AccountingRebuildBalancesCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'accounting:rebuild-balances
        {--dry-run : Report how many accounts would change without writing}';

    protected $description = 'Rebuild stored account balances from ledger transaction lines';

    public function handle(AccountingService $accounting): int
    {
        if ($this->option('dry-run')) {
            $wouldChange = 0;

            Account::query()
                ->whereHas('transactions')
                ->orderBy('id')
                ->eachById(function (Account $account) use ($accounting, &$wouldChange): void {
                    $expected = $accounting->expectedBalanceFromTransactionLines($account);

                    if (abs((float) $account->balance - $expected) > 0.004) {
                        $wouldChange++;
                        $this->line(sprintf(
                            'Account #%d (%s): stored %s → expected %s',
                            $account->id,
                            $account->name,
                            number_format((float) $account->balance, 2, '.', ''),
                            number_format($expected, 2, '.', ''),
                        ));
                    }
                });

            $this->info(__('Accounts needing correction: :count', ['count' => $wouldChange]));

            return self::SUCCESS;
        }

        $corrected = $accounting->rebuildAllLedgerAccountBalancesFromTransactionLines();

        $this->info(__('Rebuilt ledger balances. Accounts corrected: :count', ['count' => $corrected]));

        return self::SUCCESS;
    }
}
