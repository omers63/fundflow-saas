<?php

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Notifications\Tenant\FundPostingAcceptedNotification;
use App\Notifications\Tenant\FundPostingBankClearedNotification;
use App\Notifications\Tenant\FundPostingRejectedNotification;
use App\Notifications\Tenant\NewFundPostingNotification;
use Illuminate\Support\Facades\DB;

class FundPostingService
{
    public function __construct(
        public AccountingService $accounting,
        private BankTransactionClearanceService $bankClearance,
        private OperationalReviewWorkflowService $reviewWorkflow,
        private SyntheticBankStatementFactory $syntheticStatements,
        private BankClearanceLinkageResolver $clearanceLinkageResolver,
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

            $statement = $this->syntheticStatements->memberPostings();

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

            $this->notifyAdminsOfNewPosting($posting);

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

            $this->reviewWorkflow->markReviewed($posting, 'accepted', $reviewedBy, $remarks);

            $this->updateLinkedBankTransactionStatus($posting, 'posted');

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
            $this->reviewWorkflow->markReviewed($posting, 'rejected', $reviewedBy, $remarks);

            $this->updateLinkedBankTransactionStatus($posting, 'ignored');

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

    private function notifyAdminsOfNewPosting(FundPosting $posting): void
    {
        $this->reviewWorkflow->notifyAdmins(new NewFundPostingNotification($posting));
    }

    private function updateLinkedBankTransactionStatus(FundPosting $posting, string $status): void
    {
        $bankTransaction = $posting->bankTransaction;

        if ($bankTransaction === null) {
            return;
        }

        $bankTransaction->update(['status' => $status]);
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
        $this->bankClearance->clearMatchedPair(
            $uncleared,
            $imported,
            $this->clearanceLinkageResolver->forFundPosting($uncleared),
        );

        $fundPosting = $uncleared->fundPosting()->with('member.user')->first();
        $memberUser = $fundPosting?->member?->user;
        if ($memberUser !== null) {
            $memberUser->notify(new FundPostingBankClearedNotification($fundPosting));
        }
    }
}
