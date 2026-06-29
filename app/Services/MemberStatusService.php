<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use App\Support\MemberMembershipPolicy;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class MemberStatusService
{
    public function __construct(
        private readonly FundAuditLogService $audit,
        private readonly AccountingService $accounting,
        private readonly MemberFundCashTransferService $fundCashTransfer,
        private readonly MemberCashOutService $cashOuts,
        private readonly MemberAccountBalanceService $ledgerBalances,
        private readonly MemberMembershipPolicy $policy,
    ) {}

    public function freeze(
        Member $member,
        string $reason = '',
        ?CarbonInterface $freezeDate = null,
        bool $cashOutBalances = false,
    ): void {
        if ($member->status !== 'active') {
            throw new InvalidArgumentException(__('Only active members can be frozen.'));
        }

        $normalizedFreezeDate = $this->normalizeFreezeDate($freezeDate);

        if ($cashOutBalances) {
            $this->settleBalancesBeforeFreeze($member, $reason, $normalizedFreezeDate);
        }

        $this->transition($member, 'inactive', [
            'contribution_cycles_active' => false,
            'frozen_at' => $normalizedFreezeDate,
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

    public function suspend(Member $member, string $reason = ''): void
    {
        if ($member->status === 'inactive' && $member->frozen_at === null) {
            throw new InvalidArgumentException(__('Member is already suspended.'));
        }

        if ($this->policy->isExitStatus($member->status)) {
            throw new InvalidArgumentException(__('Cannot suspend a withdrawn member.'));
        }

        if ($member->status === 'inactive' && $member->frozen_at !== null) {
            throw new InvalidArgumentException(__('Cannot suspend a frozen member. Unfreeze first.'));
        }

        $this->transition($member, 'inactive', [
            'contribution_cycles_active' => false,
            'frozen_at' => null,
        ], 'MEMBER_SUSPENDED', $reason, $member->status);
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
            throw new InvalidArgumentException(__('Member is not suspended.'));
        }

        $this->transition($member, 'active', [
            'contribution_cycles_active' => true,
            'frozen_at' => null,
        ], 'MEMBER_RESTORED', '', 'inactive');
    }

    public function withdraw(Member $member, string $reason = ''): void
    {
        if ($member->status === 'withdrawn') {
            throw new InvalidArgumentException(__('Member has already withdrawn.'));
        }

        $this->transition($member, 'withdrawn', [
            'contribution_cycles_active' => false,
            'payout_frozen_at' => null,
        ], 'MEMBER_WITHDRAWN', $reason, $member->status);
    }

    public function terminate(Member $member, string $reason = ''): void
    {
        if ($member->status === 'withdrawn' && $member->payout_frozen_at !== null) {
            throw new InvalidArgumentException(__('Member is already terminated.'));
        }

        if ($member->status === 'withdrawn' && $member->payout_frozen_at === null) {
            throw new InvalidArgumentException(__('Cannot terminate a voluntarily withdrawn member.'));
        }

        $this->transition($member, 'withdrawn', [
            'contribution_cycles_active' => false,
            'payout_frozen_at' => BusinessDay::now(),
        ], 'MEMBER_TERMINATED', $reason, $member->status);
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

    private function settleBalancesBeforeFreeze(Member $member, string $reason, CarbonInterface $freezeDate): void
    {
        $this->accounting->createMemberAccounts($member);
        $member->load(['cashAccount', 'fundAccount']);

        $balances = $this->ledgerBalances->positiveFreezeCashOutBalances($member, $freezeDate);
        $fundEligible = $balances['fund'];
        $cashEligible = $balances['cash'];
        $cashOutAmount = $balances['total'];

        if ($cashOutAmount <= 0.00001) {
            return;
        }

        $this->assertFreezeCashOutSettleable($member, $fundEligible, $cashEligible, $freezeDate);

        if ($fundEligible > 0.00001) {
            $this->fundCashTransfer->transferAmount(
                $member,
                $fundEligible,
                $member,
                __('Membership freeze — fund to cash'),
                BusinessDay::now(),
            );
        }

        $note = trim($reason) !== ''
            ? __('Auto cash-out from membership freeze: :reason', ['reason' => trim($reason)])
            : __('Auto cash-out from membership freeze');

        $this->cashOuts->submit(
            $member->fresh(),
            $cashOutAmount,
            $note,
            bypassAvailabilityGuard: true,
        );
    }

    private function assertFreezeCashOutSettleable(
        Member $member,
        float $fundEligible,
        float $cashEligible,
        CarbonInterface $freezeDate,
    ): void {
        $currentFund = round(max(0.0, $member->getFundBalance()), 2);
        $currentCash = round(max(0.0, $member->getCashBalance()), 2);

        if ($currentFund + 0.01 < $fundEligible) {
            throw new InvalidArgumentException(__('Fund balance is below the amount eligible as of :date.', [
                'date' => $freezeDate->toDateString(),
            ]));
        }

        if ($currentCash + 0.01 < $cashEligible) {
            throw new InvalidArgumentException(__('Cash balance is below the amount eligible as of :date.', [
                'date' => $freezeDate->toDateString(),
            ]));
        }
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
