<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Notifications\Tenant\LoanAdminTransferAdminNotification;
use App\Notifications\Tenant\LoanAdminTransferNotification;
use App\Services\FundAuditLogService;
use App\Services\MemberStatusService;
use App\Services\OperationalReviewWorkflowService;
use App\Support\BusinessDay;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LoanAdminTransferService
{
    public function __construct(
        private LoanGuarantorTransferService $guarantorTransfer,
        private LoanTransferUnwindService $unwind,
        private LoanLifecycleService $lifecycle,
        private FundAuditLogService $audit,
        private MemberStatusService $memberStatus,
    ) {}

    public function preview(
        Loan $loan,
        Member $recipient,
        string $mode,
        bool $fundEntirelyFromMaster = false,
    ): LoanTransferPreview {
        $this->assertTransferableLoan($loan, $mode);
        $this->assertRecipient($loan, $recipient);

        $loan->loadMissing(['member.accounts', 'installments']);
        $approved = (float) ($loan->amount_approved ?: $loan->amount);
        $memberPortion = (float) ($loan->member_portion ?? 0);
        $masterPortion = (float) ($loan->master_portion ?? 0);
        $repaidToMaster = (float) ($loan->repaid_to_master ?? 0);
        $remaining = LoanTransferPreview::remainingObligation($masterPortion, $repaidToMaster);

        $nets = $mode === LoanTransferPreview::MODE_FULL
            ? $this->unwind->computeNets($loan)
            : [
                'loan_account_balance' => 0.0,
                'member_fund_net_change' => 0.0,
                'fund_restore_amount' => 0.0,
                'direct_master_reverse_amount' => 0.0,
            ];

        $recipient->loadMissing('accounts');
        $recipientFund = (float) ($recipient->fundAccount?->balance ?? 0);
        $strategy = $fundEntirelyFromMaster
            ? LoanFundingStrategy::MEMBER_FUND_TOPUP
            : LoanFundingStrategy::normalize($loan->funding_strategy);
        $balanceForPortions = $fundEntirelyFromMaster ? 0.0 : $recipientFund;
        $portions = LoanSettings::resolveFundingPortions($approved, $balanceForPortions, $strategy);
        $requiredFund = $fundEntirelyFromMaster
            ? 0.0
            : LoanSettings::requiredMemberFundForLoanAmount($approved, $strategy);

        // For top-up strategy, required fund is the full loan when measuring eligibility to
        // cover member share from available balance; use computed member portion instead.
        if (! $fundEntirelyFromMaster && $strategy === LoanFundingStrategy::MEMBER_FUND_TOPUP) {
            $requiredFund = $portions['member_portion'];
        }

        $activeLoanCount = $this->inProgressLoanCount($recipient);
        $maxActive = LoanSettings::maxActiveLoans();
        $masterFundBalance = (float) (Account::masterFund()?->balance ?? 0);

        return new LoanTransferPreview(
            mode: $mode,
            approvedAmount: $approved,
            memberPortion: $memberPortion,
            masterPortion: $masterPortion,
            repaidToMaster: $repaidToMaster,
            remainingObligation: $remaining,
            loanAccountBalance: $nets['loan_account_balance'],
            memberFundNetChange: $nets['member_fund_net_change'],
            fundRestoreAmount: $nets['fund_restore_amount'],
            directMasterReverseAmount: $nets['direct_master_reverse_amount'],
            recipientFundBalance: $recipientFund,
            requiredRecipientFund: $requiredFund,
            redisbursePortions: $portions,
            recipientFundSufficient: $fundEntirelyFromMaster || $recipientFund + 0.01 >= $requiredFund,
            recipientAtActiveLoanCap: $activeLoanCount >= $maxActive,
            recipientActiveLoanCount: $activeLoanCount,
            maxActiveLoans: $maxActive,
            masterFundBalance: $masterFundBalance,
            masterFundSufficientForRedisburse: $masterFundBalance + 0.01 >= $portions['master_portion'],
        );
    }

    public function transfer(
        Loan $loan,
        Member $recipient,
        string $mode,
        bool $allowSecondRunningLoan = false,
        bool $fundEntirelyFromMaster = false,
        bool $suspendBorrower = true,
    ): Loan {
        $preview = $this->preview($loan, $recipient, $mode, $fundEntirelyFromMaster);

        if ($preview->recipientAtActiveLoanCap && ! $allowSecondRunningLoan) {
            throw new InvalidArgumentException(__('Recipient already has :count loan(s) in progress (maximum :max). Enable “Allow second running loan” to continue.', [
                'count' => $preview->recipientActiveLoanCount,
                'max' => $preview->maxActiveLoans,
            ]));
        }

        if ($mode === LoanTransferPreview::MODE_FULL) {
            if (! $preview->recipientFundSufficient && ! $fundEntirelyFromMaster) {
                throw new InvalidArgumentException(__('Recipient fund balance (:balance) is insufficient for the member portion (:portion). Enable “Fund entirely from master” or top up the recipient fund.', [
                    'balance' => number_format($preview->recipientFundBalance, 2),
                    'portion' => number_format($preview->requiredRecipientFund, 2),
                ]));
            }

            if (! $preview->masterFundSufficientForRedisburse) {
                throw new InvalidArgumentException(__('Insufficient master fund balance (:balance) for redisbursement master portion (:portion).', [
                    'balance' => number_format($preview->masterFundBalance, 2),
                    'portion' => number_format($preview->redisbursePortions['master_portion'], 2),
                ]));
            }
        }

        return match ($mode) {
            LoanTransferPreview::MODE_REMAINING => $this->transferRemaining($loan, $recipient, $suspendBorrower),
            LoanTransferPreview::MODE_FULL => $this->transferFull(
                $loan,
                $recipient,
                $fundEntirelyFromMaster,
                $allowSecondRunningLoan,
                $suspendBorrower,
            ),
            default => throw new InvalidArgumentException(__('Unsupported transfer mode.')),
        };
    }

    private function transferRemaining(Loan $loan, Member $recipient, bool $suspendBorrower): Loan
    {
        $borrower = $loan->member;

        if ($borrower === null) {
            throw new InvalidArgumentException(__('Loan has no borrower.'));
        }

        DB::transaction(function () use ($loan, $recipient, $borrower, $suspendBorrower): void {
            $originalBorrowerId = $loan->original_borrower_member_id ?? $borrower->id;
            $remaining = $this->guarantorTransfer->remainingGuarantorObligation($loan);

            $loan->update([
                'original_borrower_member_id' => $originalBorrowerId,
                'member_id' => $recipient->id,
                'status' => 'transferred',
                'lifecycle_stage' => 'transferred',
                'admin_transfer_mode' => LoanTransferPreview::MODE_REMAINING,
                'admin_transferred_at' => BusinessDay::now(),
                'transferred_to_guarantor_at' => $loan->guarantor_member_id === $recipient->id
                    ? BusinessDay::now()
                    : $loan->transferred_to_guarantor_at,
                'guarantor_liability_transferred_at' => $loan->guarantor_member_id === $recipient->id
                    ? BusinessDay::now()
                    : $loan->guarantor_liability_transferred_at,
            ]);

            if ($suspendBorrower) {
                $borrower->refresh();
                $this->memberStatus->suspendForGuarantorTransfer($borrower);
            }

            $loan->installments()
                ->whereIn('status', ['pending', 'overdue'])
                ->forceDelete();

            $this->guarantorTransfer->rebuildGuarantorSchedule($loan->fresh(), $recipient, $remaining);

            $loanAccount = $loan->account();
            if ($loanAccount !== null) {
                $loanAccount->update([
                    'member_id' => $recipient->id,
                    'name' => __('Loan #:id – :name', ['id' => $loan->id, 'name' => $recipient->name]),
                ]);
            }
        });

        $loan = $loan->fresh();

        $this->audit->log('LOAN_ADMIN_TRANSFER_REMAINING', 'loan', $loan, $recipient, [
            'original_borrower_id' => $borrower->id,
            'recipient_id' => $recipient->id,
            'remaining_obligation' => $this->guarantorTransfer->remainingGuarantorObligation($loan),
        ]);

        $this->notifyParties($loan, $borrower, $recipient, LoanTransferPreview::MODE_REMAINING);

        return $loan;
    }

    private function transferFull(
        Loan $loan,
        Member $recipient,
        bool $fundEntirelyFromMaster,
        bool $allowSecondRunningLoan,
        bool $suspendBorrower,
    ): Loan {
        $borrower = $loan->member;

        if ($borrower === null) {
            throw new InvalidArgumentException(__('Loan has no borrower.'));
        }

        $approved = (float) ($loan->amount_approved ?: $loan->amount);
        $strategy = $fundEntirelyFromMaster
            ? LoanFundingStrategy::MEMBER_FUND_TOPUP
            : LoanFundingStrategy::normalize($loan->funding_strategy);
        $sourceId = (int) $loan->id;

        $newLoan = DB::transaction(function () use (
            $loan,
            $recipient,
            $borrower,
            $approved,
            $strategy,
            $fundEntirelyFromMaster,
            $suspendBorrower,
            $sourceId,
        ): Loan {
            $this->unwind->unwind($loan->fresh());

            if ($suspendBorrower) {
                $borrower->refresh();
                $this->memberStatus->suspendForGuarantorTransfer($borrower);
            }

            $loan->refresh()->update([
                'original_borrower_member_id' => $loan->original_borrower_member_id ?? $borrower->id,
                'admin_transfer_mode' => LoanTransferPreview::MODE_FULL,
                'admin_transferred_at' => BusinessDay::now(),
            ]);

            $newLoan = $this->lifecycle->applyForLoan(
                member: $recipient,
                amountRequested: $approved,
                purpose: __('Transferred from loan #:id', ['id' => $sourceId]),
                guarantorMemberId: null,
                isEmergency: (bool) $loan->is_emergency,
                hasGraceCycle: (bool) $loan->has_grace_cycle,
                graceCycles: $loan->grace_cycles,
                adminOverrideEligibility: true,
                eligibilityOverrideReason: __('Admin loan transfer full redisbursement'),
                fundingStrategy: $strategy,
                cashOutExcessFund: false,
                guarantorName: __('Admin transfer'),
                bypassLoanAmountValidation: true,
            );

            $this->lifecycle->approveLoan(
                $newLoan,
                $approved,
                (bool) $loan->is_emergency,
                (bool) $loan->has_grace_cycle,
                $loan->grace_cycles,
            );

            $newLoan->refresh()->update([
                'transferred_from_loan_id' => $sourceId,
                'admin_transfer_mode' => LoanTransferPreview::MODE_FULL,
                'admin_transferred_at' => BusinessDay::now(),
                'original_borrower_member_id' => $borrower->id,
                'settlement_threshold' => $loan->settlement_threshold,
            ]);

            $this->lifecycle->disbursePartial(
                loan: $newLoan->fresh(),
                amount: $approved,
                notes: __('Admin transfer redisbursement from loan #:id', ['id' => $sourceId]),
                force: true,
                memberFundBalanceOverride: $fundEntirelyFromMaster ? 0.0 : null,
            );

            return $newLoan->fresh();
        });

        $this->audit->log('LOAN_ADMIN_TRANSFER_FULL', 'loan', $newLoan, $recipient, [
            'source_loan_id' => $sourceId,
            'original_borrower_id' => $borrower->id,
            'recipient_id' => $recipient->id,
            'fund_entirely_from_master' => $fundEntirelyFromMaster,
            'allow_second_running_loan' => $allowSecondRunningLoan,
        ]);

        $this->notifyParties($newLoan, $borrower, $recipient, LoanTransferPreview::MODE_FULL, $loan->fresh());

        return $newLoan;
    }

    private function assertTransferableLoan(Loan $loan, string $mode): void
    {
        if (! in_array($mode, [LoanTransferPreview::MODE_REMAINING, LoanTransferPreview::MODE_FULL], true)) {
            throw new InvalidArgumentException(__('Unsupported transfer mode.'));
        }

        if ($loan->status !== 'active') {
            throw new InvalidArgumentException(__('Only active, fully disbursed loans can be transferred.'));
        }

        if (! $loan->isFullyDisbursed()) {
            throw new InvalidArgumentException(__('Only fully disbursed loans can be transferred.'));
        }

        if ($loan->admin_transferred_at !== null) {
            throw new InvalidArgumentException(__('This loan has already been admin-transferred.'));
        }

        if ($loan->status === 'transferred') {
            throw new InvalidArgumentException(__('This loan has already been transferred.'));
        }
    }

    private function assertRecipient(Loan $loan, Member $recipient): void
    {
        if ((int) $recipient->id === (int) $loan->member_id) {
            throw new InvalidArgumentException(__('Recipient cannot be the current borrower.'));
        }

        if ($recipient->status !== 'active') {
            throw new InvalidArgumentException(__('Recipient membership must be active.'));
        }
    }

    private function inProgressLoanCount(Member $member): int
    {
        return Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', ['pending', 'approved', 'partially_disbursed', 'active', 'transferred'])
            ->count();
    }

    private function notifyParties(
        Loan $loan,
        Member $borrower,
        Member $recipient,
        string $mode,
        ?Loan $sourceLoan = null,
    ): void {
        try {
            $borrower->loadMissing('user');
            $recipient->loadMissing('user');

            $borrower->user?->notify(new LoanAdminTransferNotification(
                $loan,
                $borrower,
                $recipient,
                'borrower',
                $mode,
                $sourceLoan,
            ));
            $recipient->user?->notify(new LoanAdminTransferNotification(
                $loan,
                $borrower,
                $recipient,
                'recipient',
                $mode,
                $sourceLoan,
            ));

            app(OperationalReviewWorkflowService::class)
                ->notifyAdmins(new LoanAdminTransferAdminNotification(
                    $loan,
                    $borrower,
                    $recipient,
                    $mode,
                    $sourceLoan,
                ));
        } catch (\Throwable $e) {
            logger()->warning('LoanAdminTransferService: notification failed', [
                'loan_id' => $loan->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
