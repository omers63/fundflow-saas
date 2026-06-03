<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\FeeDisbursement;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Disburse master fees reserves: debit master fees only, then clear against a bank import (no cash/bank ledger).
 */
final class MasterFeeDisbursementService
{
    public function __construct(
        private AccountingService $accounting,
        private BankTransactionClearanceService $bankClearance,
        private SyntheticBankStatementFactory $syntheticStatements,
        private BankClearanceLinkageResolver $clearanceLinkageResolver,
    ) {}

    public function disburse(
        Account $masterFees,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt = null,
    ): FeeDisbursement {
        $this->assertMasterFeesAccount($masterFees);

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        if ($amount > (float) $masterFees->balance) {
            throw new InvalidArgumentException(__('Amount exceeds the available fees balance.'));
        }

        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $transactedAt = $transactedAt ?? now();

        return ReconciliationService::withoutRealtimeChecks(function () use ($masterFees, $amount, $description, $transactedAt): FeeDisbursement {
            return DB::transaction(function () use ($masterFees, $amount, $description, $transactedAt): FeeDisbursement {
                $disbursement = FeeDisbursement::create([
                    'amount' => $amount,
                    'description' => $description,
                    'transacted_at' => $transactedAt,
                ]);

                $ledgerDescription = __('Fee disbursement #:id – :description', [
                    'id' => $disbursement->id,
                    'description' => $description,
                ]);

                $this->accounting->debit(
                    $masterFees,
                    $amount,
                    $ledgerDescription.' '.__('(fee out)'),
                    $disbursement,
                    $transactedAt,
                );

                $statement = $this->syntheticStatements->masterFeeDisbursements();
                $bankTxn = $this->createFeeDisbursementBankTransaction(
                    $statement,
                    $disbursement,
                    $ledgerDescription,
                    $amount,
                    $transactedAt,
                );

                $disbursement->update(['bank_transaction_id' => $bankTxn->id]);

                return $disbursement->fresh(['bankTransaction']);
            });
        });
    }

    public function clearTransaction(BankTransaction $uncleared, BankTransaction $imported): void
    {
        $this->bankClearance->clearMatchedPair(
            $uncleared,
            $imported,
            $this->clearanceLinkageResolver->forFeeDisbursement($uncleared),
        );
    }

    private function assertMasterFeesAccount(Account $account): void
    {
        if (! $account->is_master || $account->type !== 'fees') {
            throw new InvalidArgumentException(__('Account must be the master fees account.'));
        }
    }

    private function createFeeDisbursementBankTransaction(
        BankStatement $statement,
        FeeDisbursement $disbursement,
        string $description,
        float $amount,
        DateTimeInterface $transactedAt,
    ): BankTransaction {
        return BankTransaction::create([
            'bank_statement_id' => $statement->id,
            'transaction_date' => Carbon::parse($transactedAt)->toDateString(),
            'description' => $description,
            'amount' => -$amount,
            'reference' => (string) $disbursement->id,
            'status' => 'imported',
            'member_id' => null,
            'hash' => md5("fee-disbursement-{$disbursement->id}-{$amount}"),
            'is_cleared' => false,
            'fee_disbursement_id' => $disbursement->id,
        ]);
    }
}
