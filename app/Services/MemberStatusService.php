<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Notifications\Tenant\MemberStatusChangedNotification;
use App\Support\BusinessDay;
use App\Support\MemberMembershipPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class MemberStatusService
{
    public function __construct(
        private readonly FundAuditLogService $audit,
        private readonly AccountingService $accounting,
        private readonly MemberMembershipPolicy $policy,
        private readonly MemberWithdrawalSettlementService $withdrawalSettlement,
        private readonly MemberCashOutService $cashOuts,
    ) {}

    /**
     * @param  array<string, mixed>  $freezeMeta  Planned freeze fields (cycles, household, etc.)
     */
    public function freeze(
        Member $member,
        string $reason = '',
        ?CarbonInterface $freezeDate = null,
        bool $cashOutFund = false,
        ?int $reviewedBy = null,
        array $freezeMeta = [],
    ): void {
        if ($member->status !== 'active') {
            throw new InvalidArgumentException(__('Only active members can be frozen.'));
        }

        $frozenAt = $this->normalizeFreezeDate($freezeDate);

        DB::transaction(function () use ($member, $reason, $frozenAt, $cashOutFund, $reviewedBy, $freezeMeta): void {
            $this->transition($member, 'inactive', array_merge([
                'contribution_cycles_active' => false,
                'frozen_at' => $frozenAt,
            ], $freezeMeta), 'MEMBER_FROZEN', $reason, $member->status, $frozenAt);

            // Fund cash-out on freeze is deprecated — cash-out stays frozen during membership freeze.
            if (! $cashOutFund) {
                return;
            }

            $note = trim($reason) !== '' ? trim($reason) : __('Fund balance cash-out on freeze');

            $this->cashOuts->submitFundBalanceCashOut(
                $member->fresh(),
                $note,
                $frozenAt,
                $reviewedBy,
            );
        });
    }

    public function unfreeze(Member $member): void
    {
        if ($member->status !== 'inactive' || $member->frozen_at === null) {
            throw new InvalidArgumentException(__('Member is not frozen.'));
        }

        $this->transition($member, 'active', [
            'contribution_cycles_active' => true,
            'frozen_at' => null,
            'freeze_cycles_requested' => null,
            'freeze_cycles_remaining' => null,
            'freeze_emi_cycles_pushed' => null,
            'freeze_plan_ended_at' => null,
            'freeze_household_mode' => null,
            'freeze_temporary_parent_member_id' => null,
            'freeze_origin_member_id' => null,
        ], 'MEMBER_UNFROZEN', '', 'inactive');
    }

    /**
     * @deprecated Use {@see freeze()} — voluntary admin hold is now expressed as freeze.
     */
    public function suspend(Member $member, string $reason = ''): void
    {
        $this->freeze($member, $reason, BusinessDay::today());
    }

    public function suspendForGuarantorTransfer(Member $member): void
    {
        if ($this->policy->isExitStatus($member->status)) {
            throw new InvalidArgumentException(__('Cannot suspend a member who has exited the fund.'));
        }

        $this->transition($member, 'inactive', [
            'contribution_cycles_active' => true,
            'frozen_at' => null,
        ], 'MEMBER_SUSPENDED_GUARANTOR_TRANSFER', '', $member->status);
    }

    public function restoreInactive(Member $member): void
    {
        if ($member->status !== 'inactive' || $member->frozen_at !== null) {
            throw new InvalidArgumentException(__('Member is not on administrative hold.'));
        }

        $this->transition($member, 'active', [
            'contribution_cycles_active' => true,
            'frozen_at' => null,
        ], 'MEMBER_RESTORED', '', 'inactive');
    }

    /**
     * @param  array{
     *     reason?: string,
     *     household_mode?: string,
     *     permanent_parent_member_id?: int|null,
     * }  $plan
     */
    public function withdraw(
        Member $member,
        string $reason = '',
        bool $holdPayout = false,
        ?CarbonInterface $withdrawDate = null,
        array $plan = [],
    ): void {
        if ($member->status === 'withdrawn') {
            throw new InvalidArgumentException(__('Member has already withdrawn.'));
        }

        $plan = array_merge([
            'reason' => $reason,
            'household_mode' => MemberWithdrawalSettlementService::HOUSEHOLD_SELF_ONLY,
        ], $plan);

        if ($reason === '' && filled($plan['reason'] ?? null)) {
            $reason = (string) $plan['reason'];
        }

        $withdrawnAt = $this->normalizeWithdrawDate($withdrawDate);

        try {
            DB::transaction(function () use ($member, $reason, $holdPayout, $withdrawnAt, $plan): void {
                $this->withdrawalSettlement->validatePlan($member, $plan);
                $this->withdrawalSettlement->assertCanSubmitOrApprove($member, forApprove: true);

                $mode = (string) ($plan['household_mode'] ?? MemberWithdrawalSettlementService::HOUSEHOLD_SELF_ONLY);

                if ($mode === MemberWithdrawalSettlementService::HOUSEHOLD_INCLUDE_DEPENDENTS) {
                    foreach ($this->withdrawalSettlement->cascadeWithdrawDependents($member) as $dependent) {
                        $this->withdrawMemberCore($dependent, $reason, $holdPayout, $withdrawnAt);
                    }
                } elseif ($mode === MemberWithdrawalSettlementService::HOUSEHOLD_PERMANENT_PARENT) {
                    $this->withdrawalSettlement->reassignPermanentParent(
                        $member,
                        (int) ($plan['permanent_parent_member_id'] ?? 0),
                    );
                }

                $this->withdrawMemberCore($member, $reason, $holdPayout, $withdrawnAt);
            });
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();

            throw new InvalidArgumentException((string) $message, 0, $exception);
        }
    }

    /**
     * Settle one member and mark withdrawn (no household orchestration).
     */
    private function withdrawMemberCore(
        Member $member,
        string $reason,
        bool $holdPayout,
        CarbonInterface $withdrawnAt,
    ): void {
        $member = $member->fresh() ?? $member;

        if ($member->status === 'withdrawn') {
            return;
        }

        $this->withdrawalSettlement->assertCanSubmitOrApprove($member, forApprove: true);

        $previousStatus = $member->status;

        $this->withdrawalSettlement->cancelOpenPendingContributions($member->fresh() ?? $member);
        $this->withdrawalSettlement->executeSettlement($member->fresh() ?? $member, $reason, $holdPayout, $withdrawnAt);

        $this->transition($member->fresh() ?? $member, 'withdrawn', [
            'contribution_cycles_active' => false,
            'frozen_at' => null,
            'payout_frozen_at' => $holdPayout ? $withdrawnAt : null,
            'last_withdrawn_at' => $withdrawnAt,
            'reinstated_at' => null,
            'freeze_cycles_requested' => null,
            'freeze_cycles_remaining' => null,
            'freeze_emi_cycles_pushed' => null,
            'freeze_plan_ended_at' => null,
            'freeze_household_mode' => null,
            'freeze_temporary_parent_member_id' => null,
            'freeze_origin_member_id' => null,
        ], 'MEMBER_WITHDRAWN', $reason, $previousStatus, $withdrawnAt);
    }

    public function terminate(Member $member, string $reason = '', ?CarbonInterface $withdrawDate = null): void
    {
        $this->withdraw($member, $reason, holdPayout: true, withdrawDate: $withdrawDate);
    }

    public function reinstate(Member $member, string $reason = ''): void
    {
        if ($member->status !== 'withdrawn') {
            throw new InvalidArgumentException(__('Only withdrawn members can be reinstated.'));
        }

        $previousStatus = $member->status;
        $lastWithdrawnAt = $member->last_withdrawn_at ?? $member->status_changed_at ?? BusinessDay::now();
        $reinstatedAt = BusinessDay::now();

        $this->resetMembershipBalances($member);

        $this->transition($member, 'active', [
            'contribution_cycles_active' => true,
            'payout_frozen_at' => null,
            'last_withdrawn_at' => $lastWithdrawnAt,
            'reinstated_at' => $reinstatedAt,
        ], 'MEMBER_REINSTATED', $reason, $previousStatus, $reinstatedAt);
    }

    public function releasePayoutReview(Member $member, string $reason = ''): void
    {
        if ($member->status !== 'withdrawn') {
            throw new InvalidArgumentException(__('Payout review release applies only to withdrawn members.'));
        }

        if ($member->payout_frozen_at === null) {
            throw new InvalidArgumentException(__('Payout is not frozen for this member.'));
        }

        $member->update([
            'payout_frozen_at' => null,
            'status_changed_at' => BusinessDay::now(),
            'status_reason' => trim($reason) !== '' ? trim($reason) : $member->status_reason,
        ]);

        $this->audit->log('MEMBER_PAYOUT_RELEASED', 'member', $member, $member, [
            'reason' => trim($reason),
        ]);
    }

    private function normalizeWithdrawDate(?CarbonInterface $withdrawDate): CarbonInterface
    {
        $date = $withdrawDate ?? BusinessDay::today();

        if ($date->copy()->startOfDay()->gt(BusinessDay::today())) {
            throw new InvalidArgumentException(__('Withdrawal date cannot be in the future.'));
        }

        return $date->copy()->endOfDay();
    }

    private function normalizeFreezeDate(?CarbonInterface $freezeDate): CarbonInterface
    {
        $date = $freezeDate ?? BusinessDay::today();

        if ($date->copy()->startOfDay()->gt(BusinessDay::today())) {
            throw new InvalidArgumentException(__('Freeze date cannot be in the future.'));
        }

        return $date->copy()->endOfDay();
    }

    private function transition(
        Member $member,
        string $status,
        array $extraAttributes,
        string $auditEvent,
        string $reason,
        string $previousStatus,
        ?CarbonInterface $statusChangedAt = null,
    ): void {
        $member->update(array_merge($extraAttributes, [
            'status' => $status,
            'status_reason' => trim($reason) !== '' ? trim($reason) : null,
            'status_changed_at' => $statusChangedAt ?? BusinessDay::now(),
        ]));

        $this->audit->log($auditEvent, 'member', $member, $member, [
            'previous_status' => $previousStatus,
            'reason' => trim($reason),
        ]);

        $this->forgetMembershipInsightCaches((int) $member->id);

        $this->notifyMemberOfStatusChange($member->fresh(), $auditEvent, trim($reason));
    }

    private function forgetMembershipInsightCaches(int $memberId): void
    {
        MemberWorkspaceSummaryService::forgetCached($memberId);
        MemberDetailInsightsService::forgetCachedSnapshot($memberId);
    }

    private function notifyMemberOfStatusChange(Member $member, string $auditEvent, string $reason): void
    {
        $payload = match ($auditEvent) {
            'MEMBER_FROZEN' => [
                __('Membership frozen'),
                filled($reason)
                ? $reason
                : __('Your membership has been frozen. Contact the administrator for details.'),
            ],
            'MEMBER_UNFROZEN' => [
                __('Membership unfrozen'),
                __('Your membership has been reactivated and contribution cycles are open again.'),
            ],
            'MEMBER_WITHDRAWN' => [
                __('Membership withdrawn'),
                filled($reason)
                ? $reason
                : __('Your membership withdrawal has been processed.'),
            ],
            'MEMBER_REINSTATED' => [
                __('Membership reinstated'),
                __('Your membership has been reinstated. Welcome back.'),
            ],
            'MEMBER_SUSPENDED_GUARANTOR_TRANSFER' => [
                __('Membership suspended'),
                __('Your membership was suspended after a loan was transferred to your guarantor.'),
            ],
            'MEMBER_RESTORED' => [
                __('Membership restored'),
                __('Your membership hold has been lifted.'),
            ],
            default => null,
        };

        if ($payload === null) {
            return;
        }

        try {
            $member->loadMissing('user');
            $member->user?->notify(new MemberStatusChangedNotification(
                $member,
                (string) $member->status,
                $payload[0],
                $payload[1],
            ));
        } catch (\Throwable $e) {
            logger()->warning('MemberStatusService: status notification failed', [
                'member_id' => $member->id,
                'event' => $auditEvent,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resetMembershipBalances(Member $member): void
    {
        $this->accounting->createMemberAccounts($member);
        $member->load(['cashAccount', 'fundAccount']);

        $description = __('Membership reinstatement — balance reset');
        $transactedAt = BusinessDay::now();

        $this->zeroAccountBalance($member, 'cash', $description, $transactedAt);
        $this->zeroAccountBalance($member, 'fund', $description, $transactedAt);
    }

    private function zeroAccountBalance(
        Member $member,
        string $type,
        string $description,
        CarbonInterface $transactedAt,
    ): void {
        $account = $type === 'cash' ? $member->cashAccount : $member->fundAccount;

        if ($account === null) {
            return;
        }

        $balance = (float) $account->balance;

        if (abs($balance) <= 0.00001) {
            return;
        }

        $mirrorSuffix = __('(reinstatement mirror)');

        if ($balance > 0) {
            if ($type === 'cash') {
                $this->accounting->debitMemberCashWithMasterMirror(
                    $account,
                    $balance,
                    $description,
                    $mirrorSuffix,
                    $member,
                    $transactedAt,
                    $member->id,
                );
            } else {
                $this->accounting->debitMemberFundWithMasterMirror(
                    $account,
                    $balance,
                    $description,
                    $mirrorSuffix,
                    $member,
                    $transactedAt,
                    $member->id,
                );
            }

            return;
        }

        $amount = abs($balance);

        if ($type === 'cash') {
            $this->accounting->creditMemberCashWithMasterMirror(
                $account,
                $amount,
                $description,
                $mirrorSuffix,
                $member,
                $transactedAt,
                $member->id,
            );
        } else {
            $this->accounting->creditMemberFundWithMasterMirror(
                $account,
                $amount,
                $description,
                $mirrorSuffix,
                $member,
                $transactedAt,
                $member->id,
            );
        }
    }
}
