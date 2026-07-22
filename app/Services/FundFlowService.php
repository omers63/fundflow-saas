<?php

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Support\BankTransactionWorkflow;
use App\Support\BusinessDay;
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
     */
    public function mirrorToCash(Collection|array $bankTransactionIds): int
    {
        $masterCash = Account::masterCash();
        $masterBank = Account::masterBank();
        $mirrored = 0;

        DB::transaction(function () use ($bankTransactionIds, $masterCash, $masterBank, &$mirrored) {
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

                // Master bank + master cash both credit (or both debit) for the same CSV line.
                // §5.12 allows this known same-direction bank-import shape under BankTransaction.
                if ($amount >= 0) {
                    $this->accounting->credit($masterBank, $amount, $description, $bankTxn);
                    $masterCashTransaction = $this->accounting->credit($masterCash, $amount, $description, $bankTxn);
                } else {
                    $this->accounting->debit($masterBank, abs($amount), $description, $bankTxn);
                    $masterCashTransaction = $this->accounting->debit($masterCash, abs($amount), $description, $bankTxn);
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
    public function ensureMirroredAndPostToMember(BankTransaction $bankTransaction, Member $member): void
    {
        if (! BankTransactionWorkflow::canPostToMember($bankTransaction)) {
            throw new InvalidArgumentException(__('This statement line is for bank matching only; posting was already recorded via the deposit or cash-out request.'));
        }

        // Mirror then member credit are one economic event for the bank-file path.
        // Realtime pool/member checks mid-way would false-positive (master cash moves
        // before member cash; member cash moves before the bank line is marked posted).
        ReconciliationService::withoutRealtimeChecks(function () use ($bankTransaction, $member): void {
            if ($bankTransaction->status === 'imported') {
                $this->mirrorToCash([$bankTransaction->id]);
                $bankTransaction->refresh();
            }

            if ($bankTransaction->status !== 'mirrored') {
                throw new InvalidArgumentException(__('This statement line cannot be posted to a member.'));
            }

            $this->postToMember($bankTransaction, $member);
        });
    }

    /**
     * Post a mirrored cash transaction to a specific member's cash account.
     * This reflects the credit in the member's cash account as a mirror
     * (no actual debit of master cash — just a mirror of the credit).
     */
    public function postToMember(BankTransaction $bankTransaction, Member $member): void
    {
        if (! BankTransactionWorkflow::canPostToMember($bankTransaction)) {
            throw new InvalidArgumentException(__('This statement line is for bank matching only; posting was already recorded via the deposit or cash-out request.'));
        }

        ReconciliationService::withoutRealtimeChecks(function () use ($bankTransaction, $member): void {
            DB::transaction(function () use ($bankTransaction, $member): void {
                $memberCash = $member->cashAccount;
                $amount = (float) $bankTransaction->amount;
                $description = self::postedToMemberLedgerDescription($bankTransaction);

                // Mark the bank line posted before the member cash leg so §5.13
                // direct_bank_imports_posted includes this amount if checks run.
                $bankTransaction->update([
                    'status' => 'posted',
                    'member_id' => $member->id,
                    'is_cleared' => true,
                    'cleared_at' => BusinessDay::now(),
                ]);

                // Same BankTransaction reference as bank/cash mirror legs; §5.12 allows this shape.
                if ($amount >= 0) {
                    $this->accounting->credit($memberCash, $amount, $description, $bankTransaction, null, $member->id);
                } else {
                    $this->accounting->debit($memberCash, abs($amount), $description, $bankTransaction, null, $member->id);
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
}
