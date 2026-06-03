<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\InvestReturn;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Record investment return: credit master invest only, then clear against an incoming bank import.
 */
final class MasterInvestReturnService
{
    public function __construct(
        private AccountingService $accounting,
        private BankTransactionClearanceService $bankClearance,
        private SyntheticBankStatementFactory $syntheticStatements,
        private BankClearanceLinkageResolver $clearanceLinkageResolver,
    ) {
    }

    public function record(
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

        $transactedAt = $transactedAt ?? now();

        return ReconciliationService::withoutRealtimeChecks(function () use ($masterInvest, $amount, $description, $transactedAt): InvestReturn {
            return DB::transaction(function () use ($masterInvest, $amount, $description, $transactedAt): InvestReturn {
                $investReturn = InvestReturn::create([
                    'amount' => $amount,
                    'description' => $description,
                    'transacted_at' => $transactedAt,
                ]);

                $ledgerDescription = __('Invest return #:id – :description', [
                    'id' => $investReturn->id,
                    'description' => $description,
                ]);

                $this->accounting->credit(
                    $masterInvest,
                    $amount,
                    $ledgerDescription . ' ' . __('(investment return)'),
                    $investReturn,
                    $transactedAt,
                );

                $statement = $this->syntheticStatements->masterInvestReturns();
                $bankTxn = $this->createInvestReturnBankTransaction(
                    $statement,
                    $investReturn,
                    $ledgerDescription,
                    $amount,
                    $transactedAt,
                );

                $investReturn->update(['bank_transaction_id' => $bankTxn->id]);

                return $investReturn->fresh(['bankTransaction']);
            });
        });
    }

    public function clearTransaction(BankTransaction $uncleared, BankTransaction $imported): void
    {
        $this->bankClearance->clearMatchedPair(
            $uncleared,
            $imported,
            $this->clearanceLinkageResolver->forInvestReturn($uncleared),
        );
    }

    private function assertMasterInvestAccount(Account $account): void
    {
        if (!$account->is_master || $account->type !== 'invest') {
            throw new InvalidArgumentException(__('Account must be the master invest account.'));
        }
    }

    private function createInvestReturnBankTransaction(
        BankStatement $statement,
        InvestReturn $investReturn,
        string $description,
        float $amount,
        DateTimeInterface $transactedAt,
    ): BankTransaction {
        return BankTransaction::create([
            'bank_statement_id' => $statement->id,
            'transaction_date' => Carbon::parse($transactedAt)->toDateString(),
            'description' => $description,
            'amount' => $amount,
            'reference' => (string) $investReturn->id,
            'status' => 'imported',
            'member_id' => null,
            'hash' => md5("invest-return-{$investReturn->id}-{$amount}"),
            'is_cleared' => false,
            'invest_return_id' => $investReturn->id,
        ]);
    }
}
