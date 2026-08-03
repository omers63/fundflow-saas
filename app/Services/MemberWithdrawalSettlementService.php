<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundOutRequest;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanGuarantorReplacementRequest;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\SupportRequest;
use App\Models\Tenant\User;
use App\Notifications\Tenant\MemberStatusChangedNotification;
use App\Services\Loans\LoanEarlySettlementService;
use App\Services\Loans\LoanGuarantorReplacementService;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Membership withdrawal settlement and leave-fund readiness (freeze-parity).
 *
 * 1. Block pending ops, pipeline loans, and unresolved guarantor roles.
 * 2. Early-settle every active loan owned by the member (principal + late fees from cash).
 * 3. Fund shortfall for settlement: transfer positive fund balance to cash before settling.
 * 4. After settlement, transfer remaining positive fund to cash and auto-submit + accept cash-out
 *    (unless payout is held for admin review).
 *
 * @see docs/member-status-spec.md
 */
final class MemberWithdrawalSettlementService
{
    public const HOUSEHOLD_SELF_ONLY = 'self_only';

    public const HOUSEHOLD_INCLUDE_DEPENDENTS = 'include_dependents';

    public const HOUSEHOLD_PERMANENT_PARENT = 'permanent_parent';

    /** Rate limit for pre-submit “notify borrowers to replace guarantor”. */
    public const BORROWER_REPLACE_NOTIFY_DECAY_SECONDS = 900;

    /** @var list<string> */
    private const PIPELINE_LOAN_STATUSES = ['pending', 'approved', 'partially_disbursed'];

    public function __construct(
        private readonly LoanEarlySettlementService $earlySettlement,
        private readonly MemberFundCashTransferService $fundCashTransfer,
        private readonly MemberCashOutService $cashOuts,
        private readonly AccountingService $accounting,
        private readonly LoanGuarantorReplacementService $guarantorReplacements,
    ) {}

    /**
     * @param  array{
     *     reason?: string,
     *     household_mode?: string,
     *     permanent_parent_member_id?: int|null,
     * }  $plan
     */
    public function validatePlan(Member $member, array $plan): void
    {
        $mode = (string) ($plan['household_mode'] ?? self::HOUSEHOLD_SELF_ONLY);
        $activeDependents = $this->activeDependents($member);

        if (! $member->isParent() || $activeDependents->isEmpty()) {
            return;
        }

        if (
            ! in_array($mode, [
                self::HOUSEHOLD_INCLUDE_DEPENDENTS,
                self::HOUSEHOLD_PERMANENT_PARENT,
            ], true)
        ) {
            throw ValidationException::withMessages([
                'household_mode' => __('Choose whether to withdraw all dependents or elect a permanent household parent.'),
            ]);
        }

        if ($mode !== self::HOUSEHOLD_PERMANENT_PARENT) {
            return;
        }

        $electedId = (int) ($plan['permanent_parent_member_id'] ?? 0);
        $elected = Member::query()->find($electedId);

        if (! $elected instanceof Member || (int) $elected->parent_member_id !== (int) $member->id) {
            throw ValidationException::withMessages([
                'permanent_parent_member_id' => __('Select one of your dependents as the permanent household parent.'),
            ]);
        }

        $this->assertEligiblePermanentParent($elected);
    }

    public function assertEligiblePermanentParent(Member $dependent): void
    {
        if ($dependent->status !== 'active') {
            throw ValidationException::withMessages([
                'permanent_parent_member_id' => __('The permanent parent must be an active member.'),
            ]);
        }

        if ($dependent->frozen_at !== null) {
            throw ValidationException::withMessages([
                'permanent_parent_member_id' => __('The permanent parent must not be frozen.'),
            ]);
        }

        if ($dependent->user_id === null) {
            throw ValidationException::withMessages([
                'permanent_parent_member_id' => __('The permanent parent must be able to log in to the member portal.'),
            ]);
        }

        if (! $dependent->direct_login_enabled && ! $dependent->is_separated) {
            throw ValidationException::withMessages([
                'permanent_parent_member_id' => __('The permanent parent must be an independent dependent with portal login enabled.'),
            ]);
        }

        $cash = $dependent->cashAccount;
        if ($cash === null || (float) $cash->balance <= 0) {
            throw ValidationException::withMessages([
                'permanent_parent_member_id' => __('The permanent parent must have a positive cash balance to fund household allocations.'),
            ]);
        }

        $pendingLeave = MemberRequest::query()
            ->where('requester_member_id', $dependent->id)
            ->where('type', MemberRequest::TYPE_WITHDRAW_MEMBERSHIP)
            ->where('status', MemberRequest::STATUS_PENDING)
            ->exists();

        if ($pendingLeave) {
            throw ValidationException::withMessages([
                'permanent_parent_member_id' => __('The permanent parent must not have a pending leave-fund request.'),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    public function blockingReasons(Member $member, bool $forApprove = false): array
    {
        $reasons = [];

        if ($member->status === 'withdrawn') {
            $reasons[] = __('Member has already withdrawn.');
        }

        foreach ($this->pendingOperationalLabels($member) as $label) {
            $reasons[] = __('Pending :item must be resolved first.', ['item' => $label]);
        }

        $unguaranteed = $this->unresolvedGuarantorLoans($member);

        if ($unguaranteed !== []) {
            $ids = collect($unguaranteed)->pluck('id')->implode(', ');
            $reasons[] = __('Replace guarantor and obtain acceptance on loan(s): :ids.', ['ids' => $ids]);
        }

        if ($forApprove) {
            $pendingAdmin = LoanGuarantorReplacementRequest::query()
                ->where('outgoing_guarantor_member_id', $member->id)
                ->where('status', LoanGuarantorReplacementRequest::STATUS_PENDING_ADMIN)
                ->exists();

            if ($pendingAdmin) {
                $reasons[] = __('A guarantor nomination is waiting for administrator matching.');
            }

            $pendingReplacements = LoanGuarantorReplacementRequest::query()
                ->where('outgoing_guarantor_member_id', $member->id)
                ->where('status', LoanGuarantorReplacementRequest::STATUS_PENDING_GUARANTOR)
                ->exists();

            if ($pendingReplacements) {
                $reasons[] = __('A proposed guarantor has not yet accepted the replacement.');
            }
        }

        $settlementBlockers = $this->settlementFundingBlockers($member);
        foreach ($settlementBlockers as $blocker) {
            $reasons[] = $blocker;
        }

        return $reasons;
    }

    public function assertCanSubmitOrApprove(Member $member, bool $forApprove = false): void
    {
        $reasons = $this->blockingReasons($member, $forApprove);

        if ($reasons !== []) {
            throw ValidationException::withMessages([
                'withdraw' => $reasons[0],
            ]);
        }
    }

    /**
     * @return array{
     *     can_withdraw: bool,
     *     blockers: list<string>,
     *     pipeline_loan_count: int,
     *     guarantor_obligation_count: int,
     *     active_loan_count: int,
     *     settlement_required_cash: float,
     *     member_cash_balance: float,
     *     member_fund_balance: float,
     *     projected_cash_out: float,
     *     active_dependent_count: int,
     * }
     */
    public function assess(Member $member, bool $forApprove = false): array
    {
        $member->unsetRelation('accounts');
        $member->loadMissing(['cashAccount', 'fundAccount']);

        $blockers = $this->blockingReasons($member, $forApprove);
        $activeLoans = $this->activeLoans($member);
        $settlementRequired = round($activeLoans->sum(
            fn (Loan $loan): float => $this->earlySettlement->requiredCash($loan),
        ), 2);

        $cash = round($member->getCashBalance(), 2);
        $fund = round($member->getFundBalance(), 2);
        $transferableFund = round(max(0.0, $fund), 2);
        $projectedCash = round(max(0.0, $cash + $transferableFund - max(0.0, $settlementRequired - $cash)), 2);

        return [
            'can_withdraw' => $blockers === [],
            'blockers' => $blockers,
            'pipeline_loan_count' => $this->pipelineLoans($member)->count(),
            'guarantor_obligation_count' => count($this->unresolvedGuarantorLoans($member)),
            'active_loan_count' => $activeLoans->count(),
            'settlement_required_cash' => $settlementRequired,
            'member_cash_balance' => $cash,
            'member_fund_balance' => $fund,
            'projected_cash_out' => $projectedCash,
            'active_dependent_count' => $this->activeDependents($member)->count(),
        ];
    }

    public function assertWithdrawable(Member $member, bool $forApprove = true): void
    {
        try {
            $this->assertCanSubmitOrApprove($member, $forApprove);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();

            throw new InvalidArgumentException((string) $message);
        }
    }

    /**
     * Reassign siblings to the elected dependent and promote them to root parent.
     */
    public function reassignPermanentParent(Member $leavingParent, int $electedMemberId): void
    {
        $elected = Member::query()->find($electedMemberId);

        if (! $elected instanceof Member || (int) $elected->parent_member_id !== (int) $leavingParent->id) {
            throw ValidationException::withMessages([
                'permanent_parent_member_id' => __('Select one of your dependents as the permanent household parent.'),
            ]);
        }

        $this->assertEligiblePermanentParent($elected);

        Member::query()
            ->where('parent_member_id', $leavingParent->id)
            ->where('id', '!=', $elected->id)
            ->where('status', '!=', 'withdrawn')
            ->update(['parent_member_id' => $elected->id]);

        $elected->update([
            'parent_member_id' => null,
            'freeze_temporary_parent_member_id' => null,
        ]);

        $leavingParent->update([
            'freeze_temporary_parent_member_id' => null,
            'freeze_household_mode' => null,
        ]);
    }

    /**
     * @return Collection<int, Member>
     */
    public function activeDependents(Member $member): Collection
    {
        if (! $member->isParent()) {
            return collect();
        }

        return $member->dependents()
            ->where('status', 'active')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Member>
     */
    public function cascadeWithdrawDependents(Member $member): Collection
    {
        if (! $member->isParent()) {
            return collect();
        }

        return $member->dependents()
            ->where('status', '!=', 'withdrawn')
            ->orderBy('id')
            ->get();
    }

    public function executeSettlement(
        Member $member,
        string $reason,
        bool $holdPayout = false,
        ?CarbonInterface $withdrawAt = null,
    ): ?CashOutRequest {
        $this->assertWithdrawable($member, forApprove: true);

        $this->accounting->createMemberAccounts($member);
        $member->load(['cashAccount', 'fundAccount']);

        $at = $withdrawAt ?? BusinessDay::now();

        return DB::transaction(function () use ($member, $reason, $holdPayout, $at): ?CashOutRequest {
            return AccountingService::withoutMemberCashCollection(function () use ($member, $reason, $holdPayout, $at): ?CashOutRequest {
                $this->fundActiveLoansForSettlement($member, $at);
                $this->earlySettleActiveLoans($member, $at);

                if ($holdPayout) {
                    return null;
                }

                return $this->submitAndAcceptRemainingCashOut($member, $reason, $at);
            });
        });
    }

    /**
     * Drop open pending contribution cycle rows (including partial collections) before leave-fund settlement.
     */
    public function cancelOpenPendingContributions(Member $member): void
    {
        AccountingService::withoutMemberCashCollection(function () use ($member): void {
            Contribution::query()
                ->where('member_id', $member->id)
                ->where('status', 'pending')
                ->orderBy('period')
                ->get()
                ->each(function (Contribution $contribution): void {
                    DB::transaction(function () use ($contribution): void {
                        $lateFeeCollected = $this->accounting->contributionLateFeeCollectedAmount($contribution);

                        if ($lateFeeCollected > 0.00001) {
                            $this->accounting->reverseContributionLateFee($contribution, $lateFeeCollected);
                        }

                        $principalCollected = max(
                            0.0,
                            (float) ($contribution->amount_collected ?? 0),
                        );

                        if ($principalCollected > 0.00001) {
                            $this->accounting->reverseContributionPrincipal($contribution, $principalCollected);
                        }

                        $contribution->update([
                            'late_fee_amount' => null,
                            'late_fee_tier' => null,
                            'overdue_since' => null,
                            'is_late' => false,
                            'notes' => trim(($contribution->notes ?? '').' '.__('Cancelled: membership withdrawal.')),
                        ]);

                        DB::table('contributions')->where('id', $contribution->id)->delete();
                    });
                });
        });
    }

    /**
     * Pre-submit nudge: ask borrowers to replace this outgoing guarantor.
     *
     * @return array{notified: int, loan_ids: list<int>}
     */
    public function notifyBorrowersToReplaceGuarantor(Member $outgoingGuarantor): array
    {
        $loans = collect($this->unresolvedGuarantorLoans($outgoingGuarantor));

        if ($loans->isEmpty()) {
            throw ValidationException::withMessages([
                'notify' => __('There are no borrowers who still need to replace you as guarantor.'),
            ]);
        }

        $throttleKey = $this->borrowerReplaceNotifyThrottleKey($outgoingGuarantor);

        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($throttleKey) / 60));

            throw ValidationException::withMessages([
                'notify' => __('Borrowers were notified recently. Try again in :minutes minute(s).', [
                    'minutes' => $minutes,
                ]),
            ]);
        }

        $notified = 0;

        foreach ($loans as $loan) {
            $loan->loadMissing('member.user');
            $borrower = $loan->member;
            $user = $borrower?->user;

            if (! $user instanceof User) {
                continue;
            }

            $user->notify(new MemberStatusChangedNotification(
                $borrower,
                'withdrawn',
                __('Guarantor leave pending'),
                __('Your guarantor :name plans to leave the fund. Please replace them as guarantor on loan #:id (My loans → Replace guarantor), then wait for the new guarantor to accept.', [
                    'name' => $outgoingGuarantor->name,
                    'id' => $loan->id,
                ]),
                $this->memberLoanViewUrl($loan),
            ));
            $notified++;
        }

        if ($notified === 0) {
            throw ValidationException::withMessages([
                'notify' => __('No borrower accounts could be notified. Ask an admin to help replace the guarantor.'),
            ]);
        }

        RateLimiter::hit($throttleKey, self::BORROWER_REPLACE_NOTIFY_DECAY_SECONDS);

        return [
            'notified' => $notified,
            'loan_ids' => $loans->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
        ];
    }

    /**
     * @return list<Loan>
     */
    public function unresolvedGuarantorLoans(Member $outgoingGuarantor): array
    {
        return $this->guarantorReplacements->unresolvedLoansForOutgoingGuarantor($outgoingGuarantor);
    }

    /**
     * @return list<string>
     */
    private function settlementFundingBlockers(Member $member): array
    {
        $blockers = [];
        $activeLoans = $this->activeLoans($member);
        $settlementRequired = round($activeLoans->sum(
            fn (Loan $loan): float => $this->earlySettlement->requiredCash($loan),
        ), 2);

        $cash = round($member->getCashBalance(), 2);
        $fund = round(max(0.0, $member->getFundBalance()), 2);

        if ($settlementRequired > $cash + $fund + 0.00001) {
            $blockers[] = __('Insufficient cash and fund to early-settle active loans. Required: :required, available: :available.', [
                'required' => number_format($settlementRequired, 2),
                'available' => number_format($cash + $fund, 2),
            ]);
        }

        return $blockers;
    }

    /**
     * @return list<string>
     */
    private function pendingOperationalLabels(Member $member): array
    {
        $labels = [];

        if (
            MemberRequest::query()
                ->where('requester_member_id', $member->id)
                ->where('status', MemberRequest::STATUS_PENDING)
                ->where('type', '!=', MemberRequest::TYPE_WITHDRAW_MEMBERSHIP)
                ->exists()
        ) {
            $labels[] = __('membership requests');
        }

        if (CashOutRequest::query()->where('member_id', $member->id)->where('status', 'pending')->exists()) {
            $labels[] = __('cash-out requests');
        }

        if (FundOutRequest::query()->where('member_id', $member->id)->where('status', 'pending')->exists()) {
            $labels[] = __('fund-out requests');
        }

        if (FundPosting::query()->where('member_id', $member->id)->where('status', 'pending')->exists()) {
            $labels[] = __('deposit requests');
        }

        if (
            MemberCashTransferRequest::query()
                ->where(function ($q) use ($member): void {
                    $q->where('from_member_id', $member->id)->orWhere('to_member_id', $member->id);
                })
                ->where('status', 'pending')
                ->exists()
        ) {
            $labels[] = __('cash transfer requests');
        }

        if (
            SupportRequest::query()
                ->where('member_id', $member->id)
                ->whereIn('status', [SupportRequest::STATUS_OPEN, SupportRequest::STATUS_IN_PROGRESS])
                ->exists()
        ) {
            $labels[] = __('support tickets');
        }

        if (
            Loan::query()
                ->where('member_id', $member->id)
                ->whereIn('status', self::PIPELINE_LOAN_STATUSES)
                ->exists()
        ) {
            $labels[] = __('pipeline loans');
        }

        return $labels;
    }

    /**
     * @return Collection<int, Loan>
     */
    private function pipelineLoans(Member $member): Collection
    {
        return Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', self::PIPELINE_LOAN_STATUSES)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Loan>
     */
    private function activeLoans(Member $member): Collection
    {
        return Loan::query()
            ->where('member_id', $member->id)
            ->where('status', 'active')
            ->with('installments')
            ->orderBy('id')
            ->get();
    }

    private function fundActiveLoansForSettlement(Member $member, CarbonInterface $at): void
    {
        $required = round($this->activeLoans($member)->sum(
            fn (Loan $loan): float => $this->earlySettlement->requiredCash($loan),
        ), 2);

        if ($required <= 0.00001) {
            return;
        }

        $member->unsetRelation('accounts');
        $cash = $member->getCashBalance();

        if ($cash >= $required - 0.00001) {
            return;
        }

        $shortfall = $required - $cash;
        $transferable = round(max(0.0, $member->getFundBalance()), 2);
        $transferAmount = round(min($shortfall, $transferable), 2);

        if ($transferAmount <= 0.00001) {
            throw new RuntimeException(__('Insufficient cash and fund to early-settle active loans.'));
        }

        $this->fundCashTransfer->transferAmount(
            $member,
            $transferAmount,
            $member,
            __('Membership withdrawal — fund to cash for loan settlement'),
            $at,
        );
    }

    private function earlySettleActiveLoans(Member $member, CarbonInterface $at): void
    {
        foreach ($this->activeLoans($member->fresh()) as $loan) {
            $this->earlySettlement->earlySettle(
                $loan->fresh(['member', 'installments']),
                sendNotification: false,
                transactedAt: $at,
            );
        }
    }

    private function submitAndAcceptRemainingCashOut(
        Member $member,
        string $reason,
        CarbonInterface $at,
    ): ?CashOutRequest {
        $request = $this->submitRemainingCashOut($member, $reason, $at);

        if ($request === null) {
            return null;
        }

        MemberCashOutService::withoutNotifications(function () use ($request, $at): void {
            $this->cashOuts->accept(
                $request->fresh(),
                null,
                __('Membership withdrawal'),
                $at,
                bypassAvailabilityGuard: true,
            );
        });

        return $request->fresh();
    }

    private function submitRemainingCashOut(Member $member, string $reason, CarbonInterface $at): ?CashOutRequest
    {
        $member = $member->fresh();
        $member->unsetRelation('accounts');
        $member->load(['cashAccount', 'fundAccount']);

        $this->fundCashTransfer->transferPositiveFundBalanceToCash(
            $member,
            $member,
            __('Membership withdrawal — fund to cash'),
            $at,
        );

        $member = $member->fresh();
        $member->unsetRelation('accounts');
        $cashOutAmount = round(max(0.0, $member->getCashBalance()), 2);

        if ($cashOutAmount <= 0.00001) {
            return null;
        }

        $note = trim($reason) !== ''
            ? __('Auto cash-out from membership withdrawal: :reason', ['reason' => trim($reason)])
            : __('Auto cash-out from membership withdrawal');

        return MemberCashOutService::withoutNotifications(
            fn (): CashOutRequest => $this->cashOuts->submit(
                $member,
                $cashOutAmount,
                $note,
                bypassAvailabilityGuard: true,
            ),
        );
    }

    private function borrowerReplaceNotifyThrottleKey(Member $outgoingGuarantor): string
    {
        return 'withdraw-guarantor-replace-notify:'.$outgoingGuarantor->id;
    }

    private function memberLoanViewUrl(Loan $loan): ?string
    {
        try {
            return MyLoanResource::getUrl('view', ['record' => $loan], panel: 'member');
        } catch (Throwable) {
            return null;
        }
    }
}
