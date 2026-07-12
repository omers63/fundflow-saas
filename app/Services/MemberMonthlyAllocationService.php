<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class MemberMonthlyAllocationService
{
    public function __construct(
        private readonly LoanDelinquencyService $delinquency,
    ) {}

    public function isSponsoredDependent(Member $member): bool
    {
        $member = $member->fresh() ?? $member;

        return $member->parent_member_id !== null;
    }

    public function canSelfChangeMonthlyContribution(Member $member): bool
    {
        if ($this->isSponsoredDependent($member)) {
            return false;
        }

        return $this->canChangeMonthlyContribution($member);
    }

    public function assertCanSelfChangeMonthlyContribution(Member $member): void
    {
        if ($this->isSponsoredDependent($member)) {
            throw new InvalidArgumentException($this->sponsoredDependentAllocationMessage());
        }

        $this->assertCanChangeMonthlyContribution($member);
    }

    public function canChangeMonthlyContribution(Member $member): bool
    {
        return ! $this->householdHasUnpaidArrears($member);
    }

    public function assertCanChangeMonthlyContribution(Member $member): void
    {
        if ($this->canChangeMonthlyContribution($member)) {
            return;
        }

        throw new InvalidArgumentException($this->allocationChangeBlockedMessage($member));
    }

    public function sponsoredDependentAllocationMessage(): string
    {
        return __('Your monthly contribution is set by your household parent. They can update it from Dependents.');
    }

    public function allocationChangeBlockedMessage(Member $member): string
    {
        if ($this->isSponsoredDependent($member)) {
            return $this->sponsoredDependentAllocationMessage();
        }

        if ($this->memberManagesHouseholdAllocations($member)) {
            return __('Clear contribution and repayment arrears from prior cycles for every sponsored member in your household before changing monthly allocations.');
        }

        return __('Clear contribution and repayment arrears from prior cycles before changing your monthly allocation.');
    }

    public function householdHasUnpaidArrears(Member $member): bool
    {
        foreach ($this->householdMembers($member) as $householdMember) {
            if ($this->delinquency->memberHasArrearsExcludingOpenCycle($householdMember)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Members whose arrears gate allocation changes for this member.
     *
     * @return Collection<int, Member>
     */
    public function householdMembers(Member $member): Collection
    {
        $member = $member->fresh() ?? $member;

        if ($member->parent_member_id !== null) {
            return collect([$member]);
        }

        if ($member->dependents()->exists()) {
            return $this->parentHouseholdMembers($member);
        }

        return collect([$member]);
    }

    public function memberManagesHouseholdAllocations(Member $member): bool
    {
        $member = $member->fresh() ?? $member;

        return $member->parent_member_id === null
            && $member->dependents()->exists();
    }

    /**
     * @return Collection<int, Member>
     */
    protected function parentHouseholdMembers(Member $parent): Collection
    {
        /** @var EloquentCollection<int, Member> $dependents */
        $dependents = $parent->dependents()->get();

        return $dependents->push($parent)->unique('id')->values();
    }
}
