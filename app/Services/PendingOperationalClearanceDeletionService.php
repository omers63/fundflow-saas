<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\ExpenseDisbursement;
use App\Models\Tenant\FeeDisbursement;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\InvestReturn;
use App\Models\Tenant\Transaction;
use App\Support\BankStatementBuckets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PendingOperationalClearanceDeletionService
{
    private const string REVERSAL_REASON = 'Pending bank match removed';

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
        if ($bankTransaction->is_cleared) {
            return __('Only uncleared pending bank match lines can be deleted.');
        }

        if (
            $bankTransaction->fund_posting_id === null
            && $bankTransaction->cash_out_request_id === null
            && $bankTransaction->expense_disbursement_id === null
            && $bankTransaction->fee_disbursement_id === null
            && $bankTransaction->invest_disbursement_id === null
            && $bankTransaction->invest_return_id === null
        ) {
            return __('This line is not a pending operational bank match entry.');
        }

        $filename = $bankTransaction->bankStatement?->filename;

        if ($filename === null || !in_array($filename, BankStatementBuckets::OPERATIONAL_CLEARANCE, true)) {
            return __('This line is not on a pending bank match statement.');
        }

        return null;
    }

    public static function modalDescription(BankTransaction $bankTransaction): string
    {
        $ledgerCount = 0;

        if ($bankTransaction->fund_posting_id !== null) {
            $posting = FundPosting::query()->find($bankTransaction->fund_posting_id);

            if ($posting?->status === 'accepted') {
                $ledgerCount = Transaction::query()
                    ->where('reference_type', $posting->getMorphClass())
                    ->where('reference_id', $posting->id)
                    ->count();
            }
        } elseif ($bankTransaction->cash_out_request_id !== null) {
            $ledgerCount = Transaction::query()
                ->where('reference_type', (new CashOutRequest)->getMorphClass())
                ->where('reference_id', $bankTransaction->cash_out_request_id)
                ->count();
        } elseif ($bankTransaction->expense_disbursement_id !== null) {
            $ledgerCount = Transaction::query()
                ->where('reference_type', (new ExpenseDisbursement)->getMorphClass())
                ->where('reference_id', $bankTransaction->expense_disbursement_id)
                ->count();
        } elseif ($bankTransaction->fee_disbursement_id !== null) {
            $ledgerCount = Transaction::query()
                ->where('reference_type', (new FeeDisbursement)->getMorphClass())
                ->where('reference_id', $bankTransaction->fee_disbursement_id)
                ->count();
        } elseif ($bankTransaction->invest_disbursement_id !== null) {
            $ledgerCount = Transaction::query()
                ->where('reference_type', (new InvestDisbursement)->getMorphClass())
                ->where('reference_id', $bankTransaction->invest_disbursement_id)
                ->count();
        } elseif ($bankTransaction->invest_return_id !== null) {
            $ledgerCount = Transaction::query()
                ->where('reference_type', (new InvestReturn)->getMorphClass())
                ->where('reference_id', $bankTransaction->invest_return_id)
                ->count();
        }

        $base = __('This removes the pending bank match line and cancels the linked operation.');

        if ($ledgerCount > 0) {
            return $base . ' ' . __(':count linked ledger transaction(s) will be reversed and balances adjusted.', [
                'count' => $ledgerCount,
            ]);
        }

        return $base;
    }

    public function delete(BankTransaction $bankTransaction): void
    {
        $reason = self::blockReason($bankTransaction);

        if ($reason !== null) {
            throw new InvalidArgumentException($reason);
        }

        ReconciliationService::withoutRealtimeChecks(function () use ($bankTransaction): void {
            DB::transaction(function () use ($bankTransaction): void {
                if ($bankTransaction->fund_posting_id !== null) {
                    $this->deleteFundPostingLine($bankTransaction);

                    return;
                }

                if ($bankTransaction->cash_out_request_id !== null) {
                    $this->deleteCashOutLine($bankTransaction);

                    return;
                }

                if ($bankTransaction->expense_disbursement_id !== null) {
                    $this->deleteExpenseDisbursementLine($bankTransaction);

                    return;
                }

                if ($bankTransaction->fee_disbursement_id !== null) {
                    $this->deleteFeeDisbursementLine($bankTransaction);

                    return;
                }

                if ($bankTransaction->invest_disbursement_id !== null) {
                    $this->deleteInvestDisbursementLine($bankTransaction);

                    return;
                }

                if ($bankTransaction->invest_return_id !== null) {
                    $this->deleteInvestReturnLine($bankTransaction);
                }
            });
        });
    }

    private function deleteFundPostingLine(BankTransaction $bankTransaction): void
    {
        $posting = FundPosting::query()->findOrFail($bankTransaction->fund_posting_id);

        if ($posting->status === 'accepted') {
            $this->reverseSourceLedger($posting);
        }

        $posting->update([
            'bank_transaction_id' => null,
            'status' => 'rejected',
            'admin_remarks' => __(self::REVERSAL_REASON),
        ]);

        $this->removeBankTransaction($bankTransaction);
    }

    private function deleteCashOutLine(BankTransaction $bankTransaction): void
    {
        $request = CashOutRequest::query()->findOrFail($bankTransaction->cash_out_request_id);

        if ($request->status === 'accepted') {
            $this->reverseSourceLedger($request);
        }

        $request->update([
            'bank_transaction_id' => null,
            'status' => 'rejected',
            'admin_remarks' => __(self::REVERSAL_REASON),
        ]);

        $this->removeBankTransaction($bankTransaction);
    }

    private function deleteExpenseDisbursementLine(BankTransaction $bankTransaction): void
    {
        $disbursement = ExpenseDisbursement::query()->findOrFail($bankTransaction->expense_disbursement_id);

        $this->reverseSourceLedger($disbursement);

        $disbursement->update(['bank_transaction_id' => null]);

        $this->removeBankTransaction($bankTransaction);

        $disbursement->delete();
    }

    private function deleteFeeDisbursementLine(BankTransaction $bankTransaction): void
    {
        $disbursement = FeeDisbursement::query()->findOrFail($bankTransaction->fee_disbursement_id);

        $this->reverseSourceLedger($disbursement);

        $disbursement->update(['bank_transaction_id' => null]);

        $this->removeBankTransaction($bankTransaction);

        $disbursement->delete();
    }

    private function deleteInvestDisbursementLine(BankTransaction $bankTransaction): void
    {
        $disbursement = InvestDisbursement::query()->findOrFail($bankTransaction->invest_disbursement_id);

        $this->reverseSourceLedger($disbursement);

        $disbursement->update(['bank_transaction_id' => null]);

        $this->removeBankTransaction($bankTransaction);

        $disbursement->delete();
    }

    private function deleteInvestReturnLine(BankTransaction $bankTransaction): void
    {
        $investReturn = InvestReturn::query()->findOrFail($bankTransaction->invest_return_id);

        $this->reverseSourceLedger($investReturn);

        $investReturn->update(['bank_transaction_id' => null]);

        $this->removeBankTransaction($bankTransaction);

        $investReturn->delete();
    }

    private function reverseSourceLedger(Model $source): void
    {
        $reason = __(self::REVERSAL_REASON);

        AccountingService::withoutMemberCashCollection(function () use ($source, $reason): void {
            $siblings = Transaction::query()
                ->where('reference_type', $source->getMorphClass())
                ->where('reference_id', $source->getKey())
                ->get();

            if ($siblings->isEmpty()) {
                return;
            }

            foreach ($siblings as $entry) {
                $this->reverseLedgerEntryWithoutPoolMirror($entry, $reason);
            }

            if ($source instanceof FundPosting) {
                $this->reverseUnreferencedMasterCashPoolMirrors($source, $reason);
            }
        });
    }

    private function reverseLedgerEntryWithoutPoolMirror(Transaction $entry, string $reason): void
    {
        if ($this->accounting->hasExistingReversal($entry)) {
            return;
        }

        $account = $entry->account;

        if ($account === null) {
            return;
        }

        if (!$account->is_master && $account->type === 'cash') {
            $amount = round((float) $entry->amount, 2);
            $description = __('Reversal of #:id: :original — :reason', [
                'id' => $entry->id,
                'original' => $entry->description ?? '—',
                'reason' => $reason,
            ]);

            if ($entry->type === 'credit') {
                $this->accounting->debit($account, $amount, $description, $entry, $entry->transacted_at, $entry->member_id);
            } else {
                $this->accounting->credit($account, $amount, $description, $entry, $entry->transacted_at, $entry->member_id);
            }

            return;
        }

        $this->accounting->createReversalEntry($entry, $reason);
    }

    private function reverseUnreferencedMasterCashPoolMirrors(FundPosting $posting, string $reason): void
    {
        $masterCash = Account::masterCash();

        if ($masterCash === null) {
            return;
        }

        $mirrorSuffix = __('(deposit mirror)');

        $mirrors = Transaction::query()
            ->where('account_id', $masterCash->id)
            ->whereNull('reference_type')
            ->whereNull('reference_id')
            ->where('type', 'credit')
            ->where('amount', $posting->amount)
            ->whereDate('transacted_at', $posting->posting_date)
            ->where('description', 'like', '%' . $mirrorSuffix . '%')
            ->get();

        foreach ($mirrors as $mirror) {
            if ($this->accounting->hasExistingReversal($mirror)) {
                continue;
            }

            $this->accounting->createReversalEntry($mirror, $reason);
        }
    }

    private function removeBankTransaction(BankTransaction $bankTransaction): void
    {
        $bankTransaction->forceFill([
            'fund_posting_id' => null,
            'cash_out_request_id' => null,
            'expense_disbursement_id' => null,
            'fee_disbursement_id' => null,
            'invest_disbursement_id' => null,
            'invest_return_id' => null,
            'membership_application_id' => null,
        ])->saveQuietly();

        $bankTransaction->delete();
    }
}
