<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\MemberFreezeService;

/**
 * Central membership capability rules — see docs/member-status-spec.md.
 */
final class MemberMembershipPolicy
{
    /**
     * Statuses that never enter the member portal (frozen inactive is allowed read-only).
     *
     * @var list<string>
     */
    public const PORTAL_BLOCKED_STATUSES = [
        'withdrawn',
    ];

    /** @var list<string> */
    public const EXIT_STATUSES = ['withdrawn'];

    public function __construct(
        private readonly LoanDelinquencyService $delinquency,
    ) {}

    public function isPortalAccessBlocked(Member $member): bool
    {
        if ($member->status === 'withdrawn') {
            return true;
        }

        // Guarantor-transfer / admin hold (inactive without freeze) stays out of the portal.
        if ($member->status === 'inactive' && $member->frozen_at === null) {
            return true;
        }

        return false;
    }

    public function canAccessPortal(Member $member): bool
    {
        if ($this->isPortalAccessBlocked($member)) {
            return false;
        }

        if ($this->isFrozen($member)) {
            return true;
        }

        return $member->status === 'active'
            && ! $this->delinquency->isDelinquent($member);
    }

    public function isFrozen(Member $member): bool
    {
        return $member->status === 'inactive' && $member->frozen_at !== null;
    }

    /**
     * Frozen members get a read-only portal (status + unfreeze / extend only).
     */
    public function isFrozenReadOnly(Member $member): bool
    {
        return $this->isFrozen($member);
    }

    public function canMutatePortal(Member $member): bool
    {
        return $member->status === 'active'
            && ! $this->delinquency->isDelinquent($member);
    }

    public function canApplyForLoan(Member $member): bool
    {
        return $this->canMutatePortal($member);
    }

    public function canParticipateInContributionCycles(Member $member): bool
    {
        if ((float) $member->monthly_contribution_amount <= 0) {
            return false;
        }

        if ($this->isFrozen($member)) {
            return false;
        }

        if ($member->status === 'active') {
            return true;
        }

        return $member->status === 'inactive'
            && (bool) $member->contribution_cycles_active;
    }

    public function canAdminContribute(Member $member): bool
    {
        return $this->canParticipateInContributionCycles($member);
    }

    public function canRequestCashOut(Member $member): bool
    {
        if ($this->isFrozen($member)) {
            return false;
        }

        if ($member->status === 'withdrawn') {
            return ! $this->isPayoutFrozen($member);
        }

        return $member->status === 'active' && ! $this->isPayoutFrozen($member);
    }

    public function canRequestFundOut(Member $member): bool
    {
        return $this->canRequestCashOut($member);
    }

    public function canReceivePayout(Member $member): bool
    {
        return ! $this->isPayoutFrozen($member);
    }

    public function isPayoutFrozen(Member $member): bool
    {
        return $member->payout_frozen_at !== null;
    }

    public function canBeGuarantor(Member $member): bool
    {
        if ($member->status !== 'active') {
            return false;
        }

        return ! $this->delinquency->memberHasArrearsExcludingOpenCycle($member);
    }

    public function canAssignDependents(Member $member): bool
    {
        return $member->status === 'active' && $member->parent_member_id === null;
    }

    public function isExitStatus(string $status): bool
    {
        return in_array($status, self::EXIT_STATUSES, true);
    }

    public function blocksHouseholdDependents(string $parentStatus): bool
    {
        return in_array($parentStatus, ['inactive', 'withdrawn'], true);
    }

    /**
     * Whether loan late fees / overdue marking should skip this borrower.
     */
    public function suppressesLoanDelinquency(Member $member): bool
    {
        if (! $this->isFrozen($member)) {
            return false;
        }

        return app(MemberFreezeService::class)->isWithinFreezePlan($member);
    }
}
