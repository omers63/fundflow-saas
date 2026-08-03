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
use App\Services\Loans\LoanFreezeScheduleService;
use App\Services\Loans\LoanGuarantorReplacementService;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Orchestrates planned membership freeze: validation, household, EMI shift, cycle ticks.
 *
 * @see docs/member-status-spec.md
 */
final class MemberFreezeService
{
    public const HOUSEHOLD_SELF_ONLY = 'self_only';

    public const HOUSEHOLD_INCLUDE_DEPENDENTS = 'include_dependents';

    public const HOUSEHOLD_TEMP_PARENT = 'temp_parent';

    /** Minimum for *finite* freeze plans and extensions. */
    public const MIN_CYCLES = 1;

    public const MAX_CYCLES = 36;

    /**
     * Planned cycle count of 0 (or blank on submit) means indefinite:
     * fee/EMI protection continues until unfreeze (no {@see freeze_plan_ended_at}).
     */
    public const INDEFINITE_CYCLES = 0;

    /** Rate limit for pre-submit “notify borrowers to replace guarantor”. */
    public const BORROWER_REPLACE_NOTIFY_DECAY_SECONDS = 900;

    public function __construct(
        private readonly MemberStatusService $statuses,
        private readonly LoanFreezeScheduleService $schedules,
        private readonly LoanGuarantorReplacementService $guarantorReplacements,
        private readonly ContributionCycleService $cycles,
        private readonly AccountingService $accounting,
    ) {}

    public function isFrozen(Member $member): bool
    {
        return $member->status === 'inactive' && $member->frozen_at !== null;
    }

    /**
     * Normalize form/payload cycle counts: blank / null / 0 → indefinite.
     */
    public static function normalizeCycles(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::INDEFINITE_CYCLES;
        }

        return max(0, (int) $value);
    }

    public static function isIndefiniteCycles(int $cycles): bool
    {
        return $cycles === self::INDEFINITE_CYCLES;
    }

    public function isIndefiniteFreeze(Member $member): bool
    {
        return $this->isFrozen($member)
            && self::isIndefiniteCycles((int) ($member->freeze_cycles_requested ?? self::INDEFINITE_CYCLES));
    }

    /**
     * Planned freeze still protects from late fees / delinquency / EMI push continues.
     */
    public function isWithinFreezePlan(Member $member): bool
    {
        if (!$this->isFrozen($member) || $member->freeze_plan_ended_at !== null) {
            return false;
        }

        if ($this->isIndefiniteFreeze($member)) {
            return true;
        }

        return (int) ($member->freeze_cycles_remaining ?? 0) > 0;
    }

    /**
     * Frozen past the planned cycle count — still frozen until unfreeze, but fees resume.
     * Indefinite freezes never exhaust until unfreeze.
     */
    public function isFreezePlanExhausted(Member $member): bool
    {
        if (!$this->isFrozen($member) || $this->isIndefiniteFreeze($member)) {
            return false;
        }

        return $member->freeze_plan_ended_at !== null
            || (int) ($member->freeze_cycles_remaining ?? 0) <= 0;
    }

    public static function formatCyclesLabel(int $cycles): string
    {
        return self::isIndefiniteCycles($cycles)
            ? __('Indefinite')
            : __(':cycles cycle(s)', ['cycles' => $cycles]);
    }

    public function hasPendingFreezeRequest(Member $member): bool
    {
        return MemberRequest::query()
            ->where('requester_member_id', $member->id)
            ->where('type', MemberRequest::TYPE_FREEZE_MEMBERSHIP)
            ->where('status', MemberRequest::STATUS_PENDING)
            ->exists();
    }

    /**
     * Dashboard / checklist: only nag about guarantor replacement while freeze is in play.
     */
    public function shouldPromptGuarantorReplacement(Member $member): bool
    {
        if (! $this->isFrozen($member) && ! $this->hasPendingFreezeRequest($member)) {
            return false;
        }

        return $this->unresolvedGuarantorLoans($member) !== [];
    }

    /**
     * @return list<string>
     */
    public function blockingReasons(Member $member, bool $forApprove = false): array
    {
        $reasons = [];

        foreach ($this->pendingOperationalLabels($member) as $label) {
            $reasons[] = __('Pending :item must be resolved first.', ['item' => $label]);
        }

        $unguaranteed = $this->activeLoansGuaranteedBy($member)
            ->filter(fn (Loan $loan): bool => ! $this->guarantorReplacements->hasAcceptedReplacement($loan, $member))
            ->values();

        if ($unguaranteed->isNotEmpty()) {
            $ids = $unguaranteed->pluck('id')->implode(', ');
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

        return $reasons;
    }

    public function assertCanSubmitOrApprove(Member $member, bool $forApprove = false): void
    {
        $reasons = $this->blockingReasons($member, $forApprove);

        if ($reasons !== []) {
            throw ValidationException::withMessages([
                'freeze' => $reasons[0],
            ]);
        }
    }

    /**
     * @param  array{
     *     cycles: int,
     *     reason?: string,
     *     household_mode?: string,
     *     temporary_parent_member_id?: int|null,
     * }  $plan
     */
    public function validatePlan(Member $member, array $plan): void
    {
        $cycles = self::normalizeCycles($plan['cycles'] ?? null);

        // 0 = indefinite; otherwise 1–MAX
        if ($cycles > self::MAX_CYCLES || ($cycles !== self::INDEFINITE_CYCLES && $cycles < self::MIN_CYCLES)) {
            throw ValidationException::withMessages([
                'cycles' => __('Freeze duration must be blank or 0 (indefinite) or between :min and :max cycles.', [
                    'min' => self::MIN_CYCLES,
                    'max' => self::MAX_CYCLES,
                ]),
            ]);
        }

        $mode = (string) ($plan['household_mode'] ?? self::HOUSEHOLD_SELF_ONLY);

        if (! $member->isParent()) {
            return;
        }

        if (
            ! in_array($mode, [
                self::HOUSEHOLD_SELF_ONLY,
                self::HOUSEHOLD_INCLUDE_DEPENDENTS,
                self::HOUSEHOLD_TEMP_PARENT,
            ], true)
        ) {
            throw ValidationException::withMessages([
                'household_mode' => __('Choose how dependents should be handled during the freeze.'),
            ]);
        }

        if ($mode !== self::HOUSEHOLD_TEMP_PARENT) {
            return;
        }

        $tempId = (int) ($plan['temporary_parent_member_id'] ?? 0);
        $temp = Member::query()->find($tempId);

        if (! $temp instanceof Member || (int) $temp->parent_member_id !== (int) $member->id) {
            throw ValidationException::withMessages([
                'temporary_parent_member_id' => __('Select one of your dependents as the temporary funding parent.'),
            ]);
        }

        $this->assertEligibleTemporaryParent($temp);
    }

    public function assertEligibleTemporaryParent(Member $dependent): void
    {
        if ($dependent->status !== 'active') {
            throw ValidationException::withMessages([
                'temporary_parent_member_id' => __('The temporary parent must be an active member.'),
            ]);
        }

        if ($dependent->user_id === null) {
            throw ValidationException::withMessages([
                'temporary_parent_member_id' => __('The temporary parent must be able to log in to the member portal.'),
            ]);
        }

        if (! $dependent->direct_login_enabled && ! $dependent->is_separated) {
            throw ValidationException::withMessages([
                'temporary_parent_member_id' => __('The temporary parent must be an independent dependent with portal login enabled.'),
            ]);
        }

        $cash = $dependent->cashAccount;
        if ($cash === null || (float) $cash->balance <= 0) {
            throw ValidationException::withMessages([
                'temporary_parent_member_id' => __('The temporary parent must have a positive cash balance to fund household allocations.'),
            ]);
        }
    }

    /**
     * @param  array{
     *     cycles: int,
     *     reason?: string,
     *     household_mode?: string,
     *     temporary_parent_member_id?: int|null,
     * }  $plan
     */
    public function applyFreeze(
        Member $member,
        array $plan,
        ?CarbonInterface $freezeDate = null,
        ?User $reviewedBy = null,
    ): void {
        if ($member->status !== 'active') {
            throw new InvalidArgumentException(__('Only active members can be frozen.'));
        }

        $plan['cycles'] = self::normalizeCycles($plan['cycles'] ?? null);
        $this->validatePlan($member, $plan);
        $this->assertCanSubmitOrApprove($member, forApprove: true);

        $cycles = self::normalizeCycles($plan['cycles']);
        $mode = (string) ($plan['household_mode'] ?? self::HOUSEHOLD_SELF_ONLY);
        $tempParentId = $mode === self::HOUSEHOLD_TEMP_PARENT
            ? (int) ($plan['temporary_parent_member_id'] ?? 0)
            : null;
        $reason = (string) ($plan['reason'] ?? '');

        DB::transaction(function () use ($member, $cycles, $mode, $tempParentId, $reason, $freezeDate): void {
            $this->statuses->freeze($member, $reason, $freezeDate, false, null, [
                'freeze_cycles_requested' => $cycles,
                'freeze_cycles_remaining' => $cycles,
                'freeze_emi_cycles_pushed' => 0,
                'freeze_plan_ended_at' => null,
                'freeze_household_mode' => $mode,
                'freeze_temporary_parent_member_id' => $tempParentId ?: null,
                'freeze_origin_member_id' => null,
            ]);

            $member->refresh();

            $this->cancelOpenPendingContributions($member);
            $this->pushEmiAndConsumeCycle($member);

            if ($mode === self::HOUSEHOLD_INCLUDE_DEPENDENTS) {
                foreach ($member->dependents as $dependent) {
                    if ($dependent->status !== 'active') {
                        continue;
                    }

                    $this->statuses->freeze($dependent, $reason, $freezeDate, false, null, [
                        'freeze_cycles_requested' => $cycles,
                        'freeze_cycles_remaining' => $cycles,
                        'freeze_emi_cycles_pushed' => 0,
                        'freeze_plan_ended_at' => null,
                        'freeze_household_mode' => self::HOUSEHOLD_SELF_ONLY,
                        'freeze_temporary_parent_member_id' => null,
                        'freeze_origin_member_id' => $member->id,
                    ]);

                    $dependent->refresh();
                    $this->cancelOpenPendingContributions($dependent);
                    $this->pushEmiAndConsumeCycle($dependent);
                }
            }

            $this->notifyFreezeStakeholders($member, requested: false);
        });
    }

    public function applyUnfreeze(Member $member): void
    {
        if (! $this->isFrozen($member)) {
            throw new InvalidArgumentException(__('Member is not frozen.'));
        }

        DB::transaction(function () use ($member): void {
            // Early unfreeze (plan not exhausted): pull EMI schedule back. After plan end, keep shifts.
            if ($member->freeze_plan_ended_at === null && (int) ($member->freeze_emi_cycles_pushed ?? 0) > 0) {
                $this->schedules->pullCyclesForMember($member, (int) $member->freeze_emi_cycles_pushed);
            }

            $originId = $member->freeze_origin_member_id;
            $mode = $member->freeze_household_mode;
            $tempParentId = $member->freeze_temporary_parent_member_id;

            $this->statuses->unfreeze($member);

            // Cascade-frozen dependents unfreeze with the origin parent.
            if ($originId === null && $mode === self::HOUSEHOLD_INCLUDE_DEPENDENTS) {
                Member::query()
                    ->where('freeze_origin_member_id', $member->id)
                    ->where('status', 'inactive')
                    ->whereNotNull('frozen_at')
                    ->each(function (Member $dependent): void {
                        $this->applyUnfreeze($dependent);
                    });
            }

            // Temp funding sponsor arrangement auto-reverts by clearing metadata on unfreeze.
            unset($tempParentId);
        });
    }

    public function extendFreeze(Member $member, int $additionalCycles, string $reason = ''): void
    {
        if (! $this->isFrozen($member)) {
            throw new InvalidArgumentException(__('Member is not frozen.'));
        }

        if ($additionalCycles < self::MIN_CYCLES || $additionalCycles > self::MAX_CYCLES) {
            throw ValidationException::withMessages([
                'cycles' => __('Extension must be between :min and :max cycles.', [
                    'min' => self::MIN_CYCLES,
                    'max' => self::MAX_CYCLES,
                ]),
            ]);
        }

        $member->update([
            'freeze_cycles_requested' => (int) $member->freeze_cycles_requested + $additionalCycles,
            'freeze_cycles_remaining' => (int) $member->freeze_cycles_remaining + $additionalCycles,
            'freeze_plan_ended_at' => null,
            'status_reason' => $reason !== '' ? $reason : $member->status_reason,
        ]);
    }

    /**
     * Called when a contribution cycle opens: consume one planned freeze cycle per frozen member still in plan.
     */
    public function tickOpenCycle(): int
    {
        $ticked = 0;

        $this->membersInActiveFreezePlanQuery()
            ->each(function (Member $member) use (&$ticked): void {
                // Indefinite freezes are advanced with period context in onContributionCycleOpened().
                if ($this->isIndefiniteFreeze($member)) {
                    return;
                }

                // First push already consumed one cycle at approve; subsequent cycle opens tick remaining.
                if ((int) $member->freeze_emi_cycles_pushed >= (int) $member->freeze_cycles_requested) {
                    $this->markPlanEnded($member);

                    return;
                }

                // Skip the same-day approve tick: remaining already reduced on apply.
                if ((int) $member->freeze_cycles_remaining >= (int) $member->freeze_cycles_requested) {
                    return;
                }

                $this->pushEmiAndConsumeCycle($member);
                $ticked++;
            });

        return $ticked;
    }

    /**
     * Better cycle tick: on each new cycle after freeze start, if remaining > 0 and we haven't
     * pushed for this cycle yet, push. Simpler approach used by {@see onContributionCycleOpened()}.
     */
    public function onContributionCycleOpened(int $month, int $year): int
    {
        $ticked = 0;

        $this->membersInActiveFreezePlanQuery()
            ->each(function (Member $member) use (&$ticked, $month, $year): void {
                // Do not double-push if frozen during this same open cycle after approve already pushed.
                $frozenAt = $member->frozen_at;
                $cycleStart = $this->cycles->cycleStartAt($month, $year);

                if ($this->isIndefiniteFreeze($member)) {
                    if ($frozenAt !== null && $frozenAt->greaterThanOrEqualTo($cycleStart)) {
                        return;
                    }

                    $this->pushEmiAndConsumeCycle($member);
                    $ticked++;

                    return;
                }

                if (
                    $frozenAt !== null && $frozenAt->greaterThanOrEqualTo($cycleStart)
                    && (int) $member->freeze_emi_cycles_pushed > 0
                    && (int) $member->freeze_cycles_remaining === (int) $member->freeze_cycles_requested - (int) $member->freeze_emi_cycles_pushed
                ) {
                    // Already handled for current cycle at approve time.
                    if ((int) $member->freeze_cycles_remaining <= 0) {
                        $this->markPlanEnded($member);
                    }

                    return;
                }

                if ((int) $member->freeze_emi_cycles_pushed >= (int) $member->freeze_cycles_requested) {
                    $this->markPlanEnded($member);

                    return;
                }

                // Only tick on cycles that open after the freeze was applied.
                if ($frozenAt !== null && $frozenAt->greaterThanOrEqualTo($cycleStart)) {
                    return;
                }

                $this->pushEmiAndConsumeCycle($member);
                $ticked++;
            });

        return $ticked;
    }

    public function notifyFreezeStakeholders(Member $member, bool $requested): void
    {
        $member->loadMissing(['user', 'loans']);

        $title = $requested
            ? __('Membership freeze requested')
            : __('Membership frozen');
        $cycles = (int) ($member->freeze_cycles_requested ?? self::INDEFINITE_CYCLES);
        $body = $requested
            ? (
                self::isIndefiniteCycles($cycles)
                ? __(':name requested an indefinite membership freeze.', ['name' => $member->name])
                : __(':name requested a membership freeze for :cycles cycle(s).', [
                    'name' => $member->name,
                    'cycles' => $cycles,
                ])
            )
            : (
                self::isIndefiniteCycles($cycles)
                ? __(':name is frozen indefinitely. Contributions and cash-out are paused.', [
                    'name' => $member->name,
                ])
                : __(':name is frozen for :cycles planned cycle(s). Contributions and cash-out are paused.', [
                    'name' => $member->name,
                    'cycles' => $cycles,
                ])
            );

        // Guarantors of the member's active loans
        Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', ['active', 'transferred', 'partially_disbursed'])
            ->whereNotNull('guarantor_member_id')
            ->with('guarantor.user')
            ->each(function (Loan $loan) use ($title, $body): void {
                $user = $loan->guarantor?->user;
                if ($user instanceof User) {
                    $user->notify(new MemberStatusChangedNotification(
                        $loan->guarantor,
                        'inactive',
                        $title,
                        $body.' '.__('Loan #:id EMI schedule may be deferred.', ['id' => $loan->id]),
                    ));
                }
            });

        // Borrowers who need a new guarantor (member is their guarantor)
        $this->activeLoansGuaranteedBy($member)->each(function (Loan $loan) use ($member, $requested): void {
            $borrower = $loan->member;
            $user = $borrower?->user;
            if (! $user instanceof User) {
                return;
            }

            $user->notify(new MemberStatusChangedNotification(
                $borrower,
                'inactive',
                $requested ? __('Guarantor freeze requested') : __('Guarantor frozen'),
                __('Your guarantor :name :action. Please select and confirm a new guarantor for loan #:id.', [
                    'name' => $member->name,
                    'action' => $requested ? __('has requested a freeze') : __('has been frozen'),
                    'id' => $loan->id,
                ]),
                $this->memberLoanViewUrl($loan),
            ));
        });

        if ($member->freeze_temporary_parent_member_id) {
            $temp = Member::query()->with('user')->find($member->freeze_temporary_parent_member_id);
            if ($temp?->user instanceof User) {
                $temp->user->notify(new MemberStatusChangedNotification(
                    $temp,
                    'active',
                    __('Temporary household funding'),
                    __('You are the temporary funding parent for :name’s dependents during their freeze.', [
                        'name' => $member->name,
                    ]),
                ));
            }
        }
    }

    public function notifyFreezeRequested(Member $member, array $plan): void
    {
        // Attach plan fields temporarily for message context
        $member->freeze_cycles_requested = self::normalizeCycles($plan['cycles'] ?? null);
        $this->notifyFreezeStakeholders($member, requested: true);
    }

    /**
     * Pre-submit nudge: ask borrowers to replace this outgoing guarantor.
     * Does not create or submit a freeze request. Rate-limited per outgoing guarantor.
     *
     * @return array{notified: int, loan_ids: list<int>}
     */
    public function notifyBorrowersToReplaceGuarantor(Member $outgoingGuarantor): array
    {
        $loans = collect($this->guarantorReplacements->unresolvedLoansForOutgoingGuarantor($outgoingGuarantor));

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
                'inactive',
                __('Guarantor freeze pending'),
                __('Your guarantor :name plans to freeze membership. Please replace them as guarantor on loan #:id (My loans → Replace guarantor), then wait for the new guarantor to accept.', [
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

    public function unresolvedGuarantorLoans(Member $outgoingGuarantor): array
    {
        return $this->guarantorReplacements->unresolvedLoansForOutgoingGuarantor($outgoingGuarantor);
    }

    private function borrowerReplaceNotifyThrottleKey(Member $outgoingGuarantor): string
    {
        return 'freeze-guarantor-replace-notify:'.$outgoingGuarantor->id;
    }

    private function memberLoanViewUrl(Loan $loan): ?string
    {
        try {
            return MyLoanResource::getUrl('view', ['record' => $loan], panel: 'member');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Frozen members still covered by the freeze plan (finite remaining, or indefinite).
     *
     * @return Builder<Member>
     */
    private function membersInActiveFreezePlanQuery()
    {
        return Member::query()
            ->where('status', 'inactive')
            ->whereNotNull('frozen_at')
            ->whereNull('freeze_plan_ended_at')
            ->where(function ($query): void {
                $query->where('freeze_cycles_remaining', '>', 0)
                    ->orWhere('freeze_cycles_requested', self::INDEFINITE_CYCLES);
            });
    }

    private function pushEmiAndConsumeCycle(Member $member): void
    {
        $this->schedules->pushOneCycleForMember($member);

        $pushed = (int) $member->freeze_emi_cycles_pushed + 1;

        if ($this->isIndefiniteFreeze($member) || self::isIndefiniteCycles((int) ($member->freeze_cycles_requested ?? -1))) {
            $member->update([
                'freeze_emi_cycles_pushed' => $pushed,
                'freeze_cycles_remaining' => self::INDEFINITE_CYCLES,
            ]);

            return;
        }

        $remaining = max(0, (int) $member->freeze_cycles_remaining - 1);

        $member->update([
            'freeze_emi_cycles_pushed' => $pushed,
            'freeze_cycles_remaining' => $remaining,
        ]);

        if ($remaining <= 0) {
            $this->markPlanEnded($member->fresh() ?? $member);
        }
    }

    private function markPlanEnded(Member $member): void
    {
        if ($member->freeze_plan_ended_at !== null) {
            return;
        }

        $member->update([
            'freeze_cycles_remaining' => 0,
            'freeze_plan_ended_at' => BusinessDay::now(),
        ]);
    }

    private function cancelOpenPendingContributions(Member $member): void
    {
        AccountingService::withoutMemberCashCollection(function () use ($member): void {
            Contribution::query()
                ->where('member_id', $member->id)
                ->where('status', 'pending')
                ->orderBy('period')
                ->get()
                ->each(function (Contribution $contribution): void {
                    $lateFeeCollected = $this->accounting->contributionLateFeeCollectedAmount($contribution);

                    DB::transaction(function () use ($contribution, $lateFeeCollected): void {
                        if ($lateFeeCollected > 0.00001) {
                            $this->accounting->reverseContributionLateFee($contribution, $lateFeeCollected);
                        }

                        $contribution->transactions()->delete();

                        $contribution->update([
                            'late_fee_amount' => null,
                            'late_fee_tier' => null,
                            'overdue_since' => null,
                            'is_late' => false,
                            'notes' => trim(($contribution->notes ?? '').' '.__('Cancelled: membership freeze.')),
                        ]);

                        DB::table('contributions')->where('id', $contribution->id)->delete();
                    });
                });
        });
    }

    /**
     * @return Collection<int, Loan>
     */
    private function activeLoansGuaranteedBy(Member $member)
    {
        return Loan::query()
            ->where('guarantor_member_id', $member->id)
            ->whereIn('status', ['active', 'transferred', 'partially_disbursed', 'approved', 'pending'])
            ->with('member.user')
            ->get();
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
                ->where('type', '!=', MemberRequest::TYPE_FREEZE_MEMBERSHIP)
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
                ->whereIn('status', ['pending', 'approved', 'partially_disbursed'])
                ->exists()
        ) {
            $labels[] = __('pipeline loans');
        }

        return $labels;
    }
}
