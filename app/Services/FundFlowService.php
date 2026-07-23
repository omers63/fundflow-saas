<?php

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Support\BankTransactionWorkflow;
use App\Support\BusinessDay;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FundFlowService
{
    public function __construct(
        public AccountingService $accounting,
    ) {}

    /**
     * Mirror selected bank transactions to the Master Cash account.
     * Credits for incoming money (contributions/deposits/repayments),
     * debits for outgoing money (loan disbursements).
     *
     * Ledger legs use each CSV line’s transaction_date (or $forceTransactedAt when set).
     *
     * @param  Collection<int, int|string>|array<int, int|string>  $bankTransactionIds
     */
    public function mirrorToCash(Collection|array $bankTransactionIds, ?DateTimeInterface $forceTransactedAt = null): int
    {
        $masterCash = Account::masterCash();
        $masterBank = Account::masterBank();
        $mirrored = 0;

        DB::transaction(function () use ($bankTransactionIds, $masterCash, $masterBank, $forceTransactedAt, &$mirrored) {
            $transactions = BankTransaction::whereIn('id', $bankTransactionIds)
                ->where('status', 'imported')
                ->lockForUpdate()
                ->get();

            foreach ($transactions as $bankTxn) {
                if (! BankTransactionWorkflow::canPostToCash($bankTxn)) {
                    continue;
                }

                $amount = (float) $bankTxn->amount;
                $description = self::mirrorToCashLedgerDescription($bankTxn);
                $transactedAt = $forceTransactedAt ?? $this->ledgerDateFromBankLine($bankTxn);

                // Master bank + master cash both credit (or both debit) for the same CSV line.
                // §5.12 allows this known same-direction bank-import shape under BankTransaction.
                if ($amount >= 0) {
                    $this->accounting->credit($masterBank, $amount, $description, $bankTxn, $transactedAt);
                    $masterCashTransaction = $this->accounting->credit($masterCash, $amount, $description, $bankTxn, $transactedAt);
                } else {
                    $this->accounting->debit($masterBank, abs($amount), $description, $bankTxn, $transactedAt);
                    $masterCashTransaction = $this->accounting->debit($masterCash, abs($amount), $description, $bankTxn, $transactedAt);
                }

                $bankTxn->update([
                    'status' => 'mirrored',
                    'master_cash_transaction_id' => $masterCashTransaction->id,
                ]);
                $mirrored++;
            }
        });

        return $mirrored;
    }

    /**
     * Post to master cash when still imported, then post to the member cash account.
     */
    public function ensureMirroredAndPostToMember(
        BankTransaction $bankTransaction,
        Member $member,
        ?DateTimeInterface $transactedAt = null,
    ): void {
        if (! BankTransactionWorkflow::canPostToMember($bankTransaction)) {
            throw new InvalidArgumentException(__('This statement line is for bank matching only; posting was already recorded via the deposit or cash-out request.'));
        }

        $transactedAt ??= $this->ledgerDateFromBankLine($bankTransaction);

        // Mirror then member credit are one economic event for the bank-file path.
        // Realtime pool/member checks mid-way would false-positive (master cash moves
        // before member cash; member cash moves before the bank line is marked posted).
        ReconciliationService::withoutRealtimeChecks(function () use ($bankTransaction, $member, $transactedAt): void {
            if ($bankTransaction->status === 'imported') {
                $this->mirrorToCash([$bankTransaction->id], $transactedAt);
                $bankTransaction->refresh();
            }

            if ($bankTransaction->status !== 'mirrored') {
                throw new InvalidArgumentException(__('This statement line cannot be posted to a member.'));
            }

            $this->postToMember($bankTransaction, $member, $transactedAt);
        });
    }

    /**
     * Post a mirrored cash transaction to a specific member's cash account.
     * This reflects the credit in the member's cash account as a mirror
     * (no actual debit of master cash — just a mirror of the credit).
     */
    public function postToMember(
        BankTransaction $bankTransaction,
        Member $member,
        ?DateTimeInterface $transactedAt = null,
    ): void {
        if (! BankTransactionWorkflow::canPostToMember($bankTransaction)) {
            throw new InvalidArgumentException(__('This statement line is for bank matching only; posting was already recorded via the deposit or cash-out request.'));
        }

        $transactedAt ??= $this->ledgerDateFromBankLine($bankTransaction);

        ReconciliationService::withoutRealtimeChecks(function () use ($bankTransaction, $member, $transactedAt): void {
            DB::transaction(function () use ($bankTransaction, $member, $transactedAt): void {
                $memberCash = $member->cashAccount;
                $amount = (float) $bankTransaction->amount;
                $description = self::postedToMemberLedgerDescription($bankTransaction);

                // Mark the bank line posted before the member cash leg so §5.13
                // direct_bank_imports_posted includes this amount if checks run.
                $bankTransaction->update([
                    'status' => 'posted',
                    'member_id' => $member->id,
                    'is_cleared' => true,
                    'cleared_at' => $transactedAt,
                ]);

                // Same BankTransaction reference as bank/cash mirror legs; §5.12 allows this shape.
                if ($amount >= 0) {
                    $this->accounting->credit($memberCash, $amount, $description, $bankTransaction, $transactedAt, $member->id);
                } else {
                    $this->accounting->debit($memberCash, abs($amount), $description, $bankTransaction, $transactedAt, $member->id);
                }
            });
        });
    }

    /**
     * Auto-match bank transactions to members based on reference or description patterns.
     * Returns matched transactions with suggested member assignments.
     *
     * @return array<int, array{transaction_id: int, member_id: int|null, confidence: string}>
     */
    public function suggestMemberMatches(Collection $bankTransactions): array
    {
        $members = Member::active()->get();
        $suggestions = [];

        foreach ($bankTransactions as $txn) {
            $bestMatch = null;
            $confidence = 'none';
            $searchText = strtolower($txn->description.' '.$txn->reference);

            foreach ($members as $member) {
                if (str_contains($searchText, strtolower($member->member_number))) {
                    $bestMatch = $member->id;
                    $confidence = 'high';
                    break;
                }

                if (str_contains($searchText, strtolower($member->name))) {
                    $bestMatch = $member->id;
                    $confidence = 'medium';
                }
            }

            $suggestions[] = [
                'transaction_id' => $txn->id,
                'member_id' => $bestMatch,
                'confidence' => $confidence,
            ];
        }

        return $suggestions;
    }

    public static function mirrorToCashLedgerDescription(BankTransaction $bankTransaction): string
    {
        return __('Bank: :description', [
            'description' => self::resolveBankLineDetail($bankTransaction),
        ]);
    }

    public static function postedToMemberLedgerDescription(BankTransaction $bankTransaction): string
    {
        return __('Posted: :description', [
            'description' => self::resolveBankLineDetail($bankTransaction),
        ]);
    }

    public static function resolveBankLineDetail(BankTransaction $bankTransaction): string
    {
        $detail = trim((string) $bankTransaction->description);

        if ($detail !== '') {
            return $detail;
        }

        $reference = trim((string) ($bankTransaction->reference ?? ''));

        if ($reference !== '') {
            return $reference;
        }

        return __('Bank import #:id', ['id' => $bankTransaction->id]);
    }

    /**
     * Ledger / clearance timestamp for a CSV bank line (falls back to business day).
     */
    public function ledgerDateFromBankLine(BankTransaction $bankTransaction): Carbon
    {
        $date = $bankTransaction->transaction_date;

        if ($date === null) {
            return BusinessDay::now();
        }

        return Carbon::parse($date)->startOfDay();
    }
}
