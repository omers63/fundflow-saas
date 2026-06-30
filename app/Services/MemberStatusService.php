<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use App\Support\MemberMembershipPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MemberStatusService
{
    public function __construct(
        private readonly FundAuditLogService $audit,
        private readonly AccountingService $accounting,
        private readonly MemberMembershipPolicy $policy,
        private readonly MemberWithdrawalSettlementService $withdrawalSettlement,
    ) {}

    public function freeze(
        Member $member,
        string $reason = '',
        ?CarbonInterface $freezeDate = null,
    ): void {
        if ($member->status !== 'active') {
            throw new InvalidArgumentException(__('Only active members can be frozen.'));
        }

        $this->transition($member, 'inactive', [
            'contribution_cycles_active' => false,
            'frozen_at' => $this->normalizeFreezeDate($freezeDate),
        ], 'MEMBER_FROZEN', $reason, $member->status);
    }

    public function unfreeze(Member $member): void
    {
        if ($member->status !== 'inactive' || $member->frozen_at === null) {
            throw new InvalidArgumentException(__('Member is not frozen.'));
        }

        $this->transition($member, 'active', [
            'contribution_cycles_active' => true,
            'frozen_at' => null,
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

    public function withdraw(
        Member $member,
        string $reason = '',
        bool $holdPayout = false,
        ?CarbonInterface $withdrawDate = null,
    ): void {
        if ($member->status === 'withdrawn') {
            throw new InvalidArgumentException(__('Member has already withdrawn.'));
        }

        $previousStatus = $member->status;
        $withdrawnAt = $this->normalizeWithdrawDate($withdrawDate);

        DB::transaction(function () use ($member, $reason, $holdPayout, $previousStatus, $withdrawnAt): void {
            $this->withdrawalSettlement->executeSettlement($member, $reason, $holdPayout, $withdrawnAt);

            $this->transition($member, 'withdrawn', [
                'contribution_cycles_active' => false,
                'frozen_at' => null,
                'payout_frozen_at' => $holdPayout ? $withdrawnAt : null,
            ], 'MEMBER_WITHDRAWN', $reason, $previousStatus, $withdrawnAt);
        });
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

        $this->resetMembershipBalances($member);

        $this->transition($member, 'active', [
            'contribution_cycles_active' => true,
            'payout_frozen_at' => null,
        ], 'MEMBER_REINSTATED', $reason, $previousStatus);
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
