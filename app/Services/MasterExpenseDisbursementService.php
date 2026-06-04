<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\ExpenseDisbursement;
use App\Support\BusinessDay;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Disburse master expense reserves: debit master expense only, then clear against a bank import (no cash/bank ledger).
 */
final class MasterExpenseDisbursementService
{
    public function __construct(
        private AccountingService $accounting,
        private BankTransactionClearanceService $bankClearance,
        private SyntheticBankStatementFactory $syntheticStatements,
        private BankClearanceLinkageResolver $clearanceLinkageResolver,
    ) {}

    public function disburse(
        Account $masterExpense,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt = null,
    ): ExpenseDisbursement {
        $this->assertMasterExpenseAccount($masterExpense);

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        if ($amount > (float) $masterExpense->balance) {
            throw new InvalidArgumentException(__('Amount exceeds the available expense balance.'));
        }

        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $transactedAt = $transactedAt ?? BusinessDay::now();

        return ReconciliationService::withoutRealtimeChecks(function () use ($masterExpense, $amount, $description, $transactedAt): ExpenseDisbursement {
            return DB::transaction(function () use ($masterExpense, $amount, $description, $transactedAt): ExpenseDisbursement {
                $disbursement = ExpenseDisbursement::create([
                    'amount' => $amount,
                    'description' => $description,
                    'transacted_at' => $transactedAt,
                ]);

                $ledgerDescription = __('Expense disbursement #:id – :description', [
                    'id' => $disbursement->id,
                    'description' => $description,
                ]);

                $this->accounting->debit(
                    $masterExpense,
                    $amount,
                    $ledgerDescription.' '.__('(expense out)'),
                    $disbursement,
                    $transactedAt,
                );

                $statement = $this->syntheticStatements->masterExpenseDisbursements();
                $bankTxn = $this->createExpenseDisbursementBankTransaction(
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
            $this->clearanceLinkageResolver->forExpenseDisbursement($uncleared),
        );
    }

    private function assertMasterExpenseAccount(Account $account): void
    {
        if (! $account->is_master || $account->type !== 'expense') {
            throw new InvalidArgumentException(__('Account must be the master expense account.'));
        }
    }

    private function createExpenseDisbursementBankTransaction(
        BankStatement $statement,
        ExpenseDisbursement $disbursement,
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
            'hash' => md5("expense-disbursement-{$disbursement->id}-{$amount}"),
            'is_cleared' => false,
            'expense_disbursement_id' => $disbursement->id,
        ]);
    }
}
