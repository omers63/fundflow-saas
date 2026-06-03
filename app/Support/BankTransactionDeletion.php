<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\FundFlowService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BankTransactionDeletion
{
    public function __construct(
        private AccountingService $accounting,
    ) {
    }

    public static function canDelete(BankTransaction $bankTransaction): bool
    {
        return self::blockReason($bankTransaction) === null;
    }

    public static function blockReason(BankTransaction $bankTransaction): ?string
    {
        if ($bankTransaction->fund_posting_id !== null) {
            return __('This line is linked to a fund posting. Reject or adjust the posting instead of deleting the line here.');
        }

        if ($bankTransaction->cash_out_request_id !== null) {
            return __('This line is linked to a cash-out request and cannot be deleted here.');
        }

        if ($bankTransaction->expense_disbursement_id !== null) {
            return __('This line is linked to an expense disbursement and cannot be deleted here.');
        }

        if ($bankTransaction->fee_disbursement_id !== null) {
            return __('This line is linked to a fee disbursement and cannot be deleted here.');
        }

        if ($bankTransaction->invest_disbursement_id !== null) {
            return __('This line is linked to an invest disbursement and cannot be deleted here.');
        }

        if ($bankTransaction->invest_return_id !== null) {
            return __('This line is linked to an invest return and cannot be deleted here.');
        }

        if ($bankTransaction->membership_application_id !== null) {
            return __('This line is linked to a membership application and cannot be deleted here.');
        }

        $filename = $bankTransaction->bankStatement?->filename;

        if ($filename !== null && in_array($filename, BankStatementBuckets::SYNTHETIC_OPERATIONAL, true)) {
            return __('Operational statement lines cannot be deleted from this screen.');
        }

        return null;
    }

    public static function ensureCanDelete(BankTransaction $bankTransaction): void
    {
        $reason = self::blockReason($bankTransaction);

        if ($reason !== null) {
            throw new InvalidArgumentException($reason);
        }
    }

    public static function modalDescription(BankTransaction $bankTransaction): string
    {
        $ledgerCount = count(self::collectRelatedLedgerTransactionIds($bankTransaction));

        $base = __('This permanently removes the statement line from the import.');

        if ($ledgerCount > 0) {
            return $base . ' ' . __(':count linked ledger transaction(s) will be removed and account balances adjusted.', [
                'count' => $ledgerCount,
            ]);
        }

        if ($bankTransaction->status === 'duplicate') {
            return $base . ' ' . __('Other lines marked as duplicates of this one will be unlinked.');
        }

        return $base;
    }

    public function delete(BankTransaction $bankTransaction): void
    {
        self::ensureCanDelete($bankTransaction);

        DB::transaction(function () use ($bankTransaction): void {
            $ledgerIds = self::collectRelatedLedgerTransactionIds($bankTransaction);

            $bankTransaction->forceFill([
                'master_cash_transaction_id' => null,
                'master_bank_transaction_id' => null,
                'master_fund_transaction_id' => null,
            ])->saveQuietly();

            BankTransaction::query()
                ->where('duplicate_of_id', $bankTransaction->id)
                ->update(['duplicate_of_id' => null]);

            foreach ($ledgerIds as $ledgerId) {
                $ledger = Transaction::query()->find($ledgerId);

                if ($ledger !== null) {
                    $this->accounting->deleteTransaction($ledger);
                }
            }

            $bankTransaction->delete();
        });
    }

    /**
     * @return list<int>
     */
    public static function collectRelatedLedgerTransactionIds(BankTransaction $bankTransaction): array
    {
        $bankTransaction->loadMissing(['member.cashAccount', 'bankStatement']);

        $ids = $bankTransaction->transactions()->pluck('id');

        foreach (['master_cash_transaction_id', 'master_bank_transaction_id', 'master_fund_transaction_id'] as $column) {
            $ledgerId = $bankTransaction->{$column};

            if ($ledgerId !== null) {
                $ids->push($ledgerId);
            }
        }

        $memberCash = $bankTransaction->member?->cashAccount;

        if ($memberCash !== null && $bankTransaction->member_id !== null) {
            $postedDescription = FundFlowService::postedToMemberLedgerDescription($bankTransaction);
            $amount = abs((float) $bankTransaction->amount);
            $type = (float) $bankTransaction->amount >= 0 ? 'credit' : 'debit';

            $ids = $ids->merge(
                Transaction::query()
                    ->where('account_id', $memberCash->id)
                    ->where('description', $postedDescription)
                    ->where('type', $type)
                    ->where('amount', $amount)
                    ->pluck('id'),
            );
        }

        return $ids->unique()->values()->all();
    }
}
