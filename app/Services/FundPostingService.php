<?php

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Notifications\Tenant\FundPostingAcceptedNotification;
use App\Notifications\Tenant\FundPostingRejectedNotification;
use App\Notifications\Tenant\NewFundPostingNotification;
use Illuminate\Support\Facades\DB;

class FundPostingService
{
    public function __construct(
        public AccountingService $accounting,
    ) {}

    /**
     * Submit a fund posting request from a member.
     * Creates the posting, creates an uncleared bank transaction,
     * and notifies admin users.
     */
    public function submit(
        Member $member,
        float $amount,
        string $postingDate,
        ?string $reference = null,
        ?string $attachment = null,
        ?string $comments = null,
    ): FundPosting {
        return DB::transaction(function () use ($member, $amount, $postingDate, $reference, $attachment, $comments) {
            $posting = FundPosting::create([
                'member_id' => $member->id,
                'posting_date' => $postingDate,
                'amount' => $amount,
                'reference' => $reference,
                'attachment' => $attachment,
                'comments' => $comments,
                'status' => 'pending',
            ]);

            $statement = BankStatement::firstOrCreate(
                ['filename' => 'member-postings', 'status' => 'completed'],
                [
                    'bank_name' => 'Member Postings',
                    'total_rows' => 0,
                    'imported_rows' => 0,
                    'duplicate_rows' => 0,
                ],
            );

            $bankTxn = BankTransaction::create([
                'bank_statement_id' => $statement->id,
                'transaction_date' => $postingDate,
                'description' => __('Deposit by :name', ['name' => $member->name]),
                'amount' => $amount,
                'reference' => $reference,
                'status' => 'imported',
                'member_id' => $member->id,
                'hash' => md5("posting-{$posting->id}-{$postingDate}-{$amount}"),
                'is_cleared' => false,
                'fund_posting_id' => $posting->id,
            ]);

            $posting->update(['bank_transaction_id' => $bankTxn->id]);

            $admins = User::where('is_admin', true)->get();
            foreach ($admins as $admin) {
                $admin->notify(new NewFundPostingNotification($posting));
            }

            return $posting;
        });
    }

    /**
     * Accept a fund posting.
     *
     * Increases the master cash pool and credits the member's cash account (see
     * fund_management_system_requirements.md — direct cash deposit). Only the
     * member line uses the FundPosting reference so paired-journal validation does
     * not treat the pool credit as an unbalanced second leg. The associated bank
     * transaction stays uncleared until matched.
     */
    public function accept(FundPosting $posting, ?int $reviewedBy = null, ?string $remarks = null): void
    {
        DB::transaction(function () use ($posting, $reviewedBy, $remarks) {
            $member = $posting->member;
            $memberCash = $member->cashAccount;
            $amount = (float) $posting->amount;
            $description = __('Deposit #:id by :name', ['id' => $posting->id, 'name' => $member->name]);
            $transactionWatermark = (int) Transaction::query()->max('id');

            $this->accounting->creditMemberCashWithMasterMirror(
                $memberCash,
                $amount,
                __('Posted: :description', ['description' => $description]),
                __('(deposit mirror)'),
                $posting,
                null,
                $member->id,
            );

            $settlement = $this->buildSettlementSummary($member, $transactionWatermark, $amount);

            $posting->update([
                'status' => 'accepted',
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
                'admin_remarks' => $remarks,
            ]);

            if ($posting->bankTransaction) {
                $posting->bankTransaction->update(['status' => 'posted']);
            }

            $this->notifyMemberOfReview($posting, 'accepted', $settlement);
        });
    }

    /**
     * Reject a fund posting.
     * Removes the associated uncleared bank transaction.
     */
    public function reject(FundPosting $posting, ?int $reviewedBy = null, ?string $remarks = null): void
    {
        DB::transaction(function () use ($posting, $reviewedBy, $remarks) {
            $posting->update([
                'status' => 'rejected',
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
                'admin_remarks' => $remarks,
            ]);

            if ($posting->bankTransaction) {
                $posting->bankTransaction->update(['status' => 'ignored']);
            }

            $this->notifyMemberOfReview($posting, 'rejected');
        });
    }

    private function notifyMemberOfReview(
        FundPosting $posting,
        string $outcome,
        ?FundPostingSettlementSummary $settlement = null,
    ): void {
        $posting->loadMissing('member.user');
        $memberUser = $posting->member?->user;

        if ($memberUser === null) {
            return;
        }

        $notification = $outcome === 'accepted'
            ? new FundPostingAcceptedNotification($posting, $settlement)
            : new FundPostingRejectedNotification($posting);

        $memberUser->notify($notification);
    }

    protected function buildSettlementSummary(
        Member $member,
        int $afterTransactionId,
        float $depositAmount,
    ): FundPostingSettlementSummary {
        $member->loadMissing('cashAccount');
        $cashAccountId = $member->cashAccount?->id;

        if ($cashAccountId === null) {
            return new FundPostingSettlementSummary(
                depositAmount: $depositAmount,
                contributionsApplied: 0.0,
                loanInstallmentsApplied: 0.0,
                remainingCash: (float) $member->fresh()->getCashBalance(),
            );
        }

        $contributionMorph = (new Contribution)->getMorphClass();
        $installmentMorph = (new LoanInstallment)->getMorphClass();

        $contributionsApplied = 0.0;
        $loanInstallmentsApplied = 0.0;

        Transaction::query()
            ->where('account_id', $cashAccountId)
            ->where('type', 'debit')
            ->where('id', '>', $afterTransactionId)
            ->get(['amount', 'reference_type'])
            ->each(function (Transaction $transaction) use ($contributionMorph, $installmentMorph, &$contributionsApplied, &$loanInstallmentsApplied): void {
                $amount = (float) $transaction->amount;

                if ($transaction->reference_type === $contributionMorph) {
                    $contributionsApplied += $amount;

                    return;
                }

                if ($transaction->reference_type === $installmentMorph) {
                    $loanInstallmentsApplied += $amount;
                }
            });

        return new FundPostingSettlementSummary(
            depositAmount: $depositAmount,
            contributionsApplied: $contributionsApplied,
            loanInstallmentsApplied: $loanInstallmentsApplied,
            remainingCash: (float) $member->fresh()->getCashBalance(),
        );
    }

    /**
     * Clear an uncleared bank transaction by matching it against an imported one.
     */
    public function clearTransaction(BankTransaction $uncleared, BankTransaction $imported): void
    {
        DB::transaction(function () use ($uncleared, $imported) {
            $posting = $uncleared->fund_posting_id !== null
                ? FundPosting::query()->find($uncleared->fund_posting_id)
                : null;

            $uncleared->update([
                'is_cleared' => true,
                'cleared_at' => now(),
            ]);

            $imported->update([
                'is_cleared' => true,
                'cleared_at' => now(),
                'fund_posting_id' => $uncleared->fund_posting_id,
                'membership_application_id' => $uncleared->membership_application_id,
                'status' => 'posted',
                'member_id' => $posting?->member_id ?? $uncleared->member_id,
            ]);
        });
    }
}
