<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BankImportCashLedgerReferenceBackfillService;
use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class BankBackfillCashLedgerReferencesCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'bank:backfill-cash-ledger-references';

    protected $description = 'Link historical bank-import master/member cash ledger rows to their CSV BankTransaction source';

    public function handle(BankImportCashLedgerReferenceBackfillService $backfill): int
    {
        $result = $backfill->backfill();

        $this->info(__('Linked :master master cash and :member member cash ledger row(s) to bank import lines.', [
            'master' => $result['master_cash'],
            'member' => $result['member_cash'],
        ]));

        return self::SUCCESS;
    }
}
