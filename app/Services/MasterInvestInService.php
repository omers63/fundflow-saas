<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\InvestReturn;
use App\Support\BusinessDay;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Combined invest-in flow: record return on master invest, then transfer proceeds back to master fund.
 */
final class MasterInvestInService
{
    public function __construct(
        private AccountingService $accounting,
        private MasterInvestReturnService $returns,
    ) {}

    public function investIn(
        Account $masterInvest,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt = null,
    ): InvestReturn {
        $this->assertMasterInvestAccount($masterInvest);

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $transactedAt = $transactedAt ?? BusinessDay::now();

        return ReconciliationService::withoutRealtimeChecks(function () use ($masterInvest, $amount, $description, $transactedAt): InvestReturn {
            return DB::transaction(function () use ($masterInvest, $amount, $description, $transactedAt): InvestReturn {
                $investReturn = $this->returns->record(
                    $masterInvest,
                    $amount,
                    $description,
                    $transactedAt,
                );

                $this->accounting->returnReserveAccountToMasterFund(
                    $masterInvest,
                    $amount,
                    $description,
                    $investReturn,
                    $transactedAt,
                );

                return $investReturn->fresh(['bankTransaction']);
            });
        });
    }

    private function assertMasterInvestAccount(Account $account): void
    {
        if (! $account->is_master || $account->type !== 'invest') {
            throw new InvalidArgumentException(__('Account must be the master invest account.'));
        }
    }
}
