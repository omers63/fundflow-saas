<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\InvestDisbursement;
use App\Support\BusinessDay;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Combined invest-out flow: fund master invest from master fund, then disburse to bank clearance.
 */
final class MasterInvestOutService
{
    public function __construct(
        private AccountingService $accounting,
        private MasterInvestDisbursementService $disbursements,
    ) {}

    public function investOut(
        Account $masterInvest,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt = null,
    ): InvestDisbursement {
        $this->assertMasterInvestAccount($masterInvest);

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $transactedAt = $transactedAt ?? BusinessDay::now();

        return ReconciliationService::withoutRealtimeChecks(function () use ($masterInvest, $amount, $description, $transactedAt): InvestDisbursement {
            return DB::transaction(function () use ($masterInvest, $amount, $description, $transactedAt): InvestDisbursement {
                $disbursement = InvestDisbursement::create([
                    'amount' => $amount,
                    'description' => $description,
                    'transacted_at' => $transactedAt,
                ]);

                $this->accounting->fundReserveAccountFromMasterFund(
                    $masterInvest,
                    $amount,
                    $description,
                    $transactedAt,
                    $disbursement,
                );

                return $this->disbursements->disburse(
                    $masterInvest,
                    $amount,
                    $description,
                    $transactedAt,
                    $disbursement,
                );
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
