<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\InvestDisbursement;
use App\Support\BusinessDay;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Disburse master invest reserves: debit master invest only, then clear against a bank import.
 */
final class MasterInvestDisbursementService
{
    public function __construct(
        private AccountingService $accounting,
        private BankTransactionClearanceService $bankClearance,
        private SyntheticBankStatementFactory $syntheticStatements,
        private BankClearanceLinkageResolver $clearanceLinkageResolver,
    ) {}

    public function disburse(
        Account $masterInvest,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt = null,
        ?InvestDisbursement $disbursement = null,
    ): InvestDisbursement {
        $this->assertMasterInvestAccount($masterInvest);

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        if ($amount > (float) $masterInvest->balance) {
            throw new InvalidArgumentException(__('Amount exceeds the available invest balance.'));
        }

        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $transactedAt = $transactedAt ?? BusinessDay::now();

        return ReconciliationService::withoutRealtimeChecks(function () use ($masterInvest, $amount, $description, $transactedAt, $disbursement): InvestDisbursement {
            return DB::transaction(function () use ($masterInvest, $amount, $description, $transactedAt, $disbursement): InvestDisbursement {
                $disbursement ??= InvestDisbursement::create([
                    'amount' => $amount,
                    'description' => $description,
                    'transacted_at' => $transactedAt,
                ]);

                $ledgerDescription = __('Invest disbursement #:id – :description', [
                    'id' => $disbursement->id,
                    'description' => $description,
                ]);

                $this->accounting->debit(
                    $masterInvest,
                    $amount,
                    $ledgerDescription.' '.__('(invest out)'),
                    $disbursement,
                    $transactedAt,
                );

                $statement = $this->syntheticStatements->masterInvestDisbursements();
                $bankTxn = $this->createInvestDisbursementBankTransaction(
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
            $this->clearanceLinkageResolver->forInvestDisbursement($uncleared),
        );
    }

    private function assertMasterInvestAccount(Account $account): void
    {
        if (! $account->is_master || $account->type !== 'invest') {
            throw new InvalidArgumentException(__('Account must be the master invest account.'));
        }
    }

    private function createInvestDisbursementBankTransaction(
        BankStatement $statement,
        InvestDisbursement $disbursement,
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
            'hash' => md5("invest-disbursement-{$disbursement->id}-{$amount}"),
            'is_cleared' => false,
            'invest_disbursement_id' => $disbursement->id,
        ]);
    }
}
