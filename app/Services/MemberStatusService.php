<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\BusinessDay;
use App\Support\MemberMembershipPolicy;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class MemberStatusService
{
    public function __construct(
        private readonly FundAuditLogService $audit,
        private readonly LoanDelinquencyService $delinquency,
        private readonly AccountingService $accounting,
        private readonly MemberMembershipPolicy $policy,
    ) {}

    public function freeze(Member $member, string $reason = ''): void
    {
        if (! in_array($member->status, ['active', 'delinquent'], true)) {
            throw new InvalidArgumentException(__('Only active or delinquent members can be frozen.'));
        }

        $this->transition($member, 'inactive', [
            'contribution_cycles_active' => false,
        ], 'MEMBER_FROZEN', $reason, $member->status);
    }

    public function unfreeze(Member $member): void
    {
        if ($member->status !== 'inactive') {
            throw new InvalidArgumentException(__('Member is not inactive.'));
        }

        $targetStatus = $this->delinquency->memberHasArrearsExcludingOpenCycle($member)
            ? 'delinquent'
            : 'active';

        $this->transition($member, $targetStatus, [
            'contribution_cycles_active' => true,
        ], 'MEMBER_UNFROZEN', '', 'inactive');
    }

    public function suspend(Member $member, string $reason = ''): void
    {
        if ($member->status === 'suspended') {
            throw new InvalidArgumentException(__('Member is already suspended.'));
        }

        if (in_array($member->status, ['withdrawn', 'terminated'], true)) {
            throw new InvalidArgumentException(__('Cannot suspend a withdrawn or terminated member.'));
        }

        $this->transition($member, 'suspended', [
            'contribution_cycles_active' => false,
        ], 'MEMBER_SUSPENDED', $reason, $member->status);
    }

    public function suspendForGuarantorTransfer(Member $member): void
    {
        if ($this->policy->isExitStatus($member->status)) {
            throw new InvalidArgumentException(__('Cannot suspend a member who has exited the fund.'));
        }

        $this->transition($member, 'suspended', [
            'contribution_cycles_active' => true,
        ], 'MEMBER_SUSPENDED_GUARANTOR_TRANSFER', '', $member->status);
    }

    public function restoreSuspended(Member $member): void
    {
        if ($member->status !== 'suspended') {
            throw new InvalidArgumentException(__('Member is not suspended.'));
        }

        $targetStatus = $this->delinquency->memberHasArrearsExcludingOpenCycle($member)
            ? 'delinquent'
            : 'active';

        $this->transition($member, $targetStatus, [
            'contribution_cycles_active' => true,
        ], 'MEMBER_RESTORED', '', 'suspended');
    }

    public function withdraw(Member $member, string $reason = ''): void
    {
        if ($member->status === 'withdrawn') {
            throw new InvalidArgumentException(__('Member has already withdrawn.'));
        }

        if ($member->status === 'terminated') {
            throw new InvalidArgumentException(__('Cannot withdraw a terminated member.'));
        }

        $this->transition($member, 'withdrawn', [
            'contribution_cycles_active' => false,
            'payout_frozen_at' => null,
        ], 'MEMBER_WITHDRAWN', $reason, $member->status);
    }

    public function terminate(Member $member, string $reason = ''): void
    {
        if ($member->status === 'terminated') {
            throw new InvalidArgumentException(__('Member is already terminated.'));
        }

        $this->transition($member, 'terminated', [
            'contribution_cycles_active' => false,
            'payout_frozen_at' => BusinessDay::now(),
        ], 'MEMBER_TERMINATED', $reason, $member->status);
    }

    public function reinstate(Member $member, string $reason = ''): void
    {
        if (! in_array($member->status, ['withdrawn', 'terminated'], true)) {
            throw new InvalidArgumentException(__('Only withdrawn or terminated members can be reinstated.'));
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
        if ($member->status !== 'terminated') {
            throw new InvalidArgumentException(__('Payout review release applies only to terminated members.'));
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

    private function transition(
        Member $member,
        string $status,
        array $extraAttributes,
        string $auditEvent,
        string $reason,
        string $previousStatus,
    ): void {
        $member->update(array_merge($extraAttributes, [
            'status' => $status,
            'status_reason' => trim($reason) !== '' ? trim($reason) : null,
            'status_changed_at' => BusinessDay::now(),
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
