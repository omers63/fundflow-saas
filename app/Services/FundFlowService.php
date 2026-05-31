<?php

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
                $amount = (float) $bankTxn->amount;
                $description = "Bank: {$bankTxn->description}";

                $this->accounting->mirror($masterBank, $amount, $description, $bankTxn);

                $masterCashTransaction = $this->accounting->mirror($masterCash, $amount, $description, $bankTxn);

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
     * Post a mirrored cash transaction to a specific member's cash account.
     * This reflects the credit in the member's cash account as a mirror
     * (no actual debit of master cash — just a mirror of the credit).
     */
    public function postToMember(BankTransaction $bankTransaction, Member $member): void
    {
        DB::transaction(function () use ($bankTransaction, $member) {
            $memberCash = $member->cashAccount;
            $amount = (float) $bankTransaction->amount;
            $description = "Posted: {$bankTransaction->description}";

            $this->accounting->mirror($memberCash, $amount, $description, $bankTransaction);

            $bankTransaction->update([
                'status' => 'posted',
                'member_id' => $member->id,
            ]);

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
}
