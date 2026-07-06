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

    public function allocationChangeBlockedMessage(Member $member): string
    {
        if ($this->memberBelongsToHousehold($member)) {
            return __('Clear contribution and repayment arrears from prior cycles for every member in your household before changing monthly allocations.');
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
     * Parent, dependents, or the member alone when not in a household.
     *
     * @return Collection<int, Member>
     */
    public function householdMembers(Member $member): Collection
    {
        $member = $member->fresh() ?? $member;

        if ($member->parent_member_id !== null) {
            $parent = Member::query()->find($member->parent_member_id);

            if ($parent === null) {
                return collect([$member]);
            }

            return $this->parentHouseholdMembers($parent);
        }

        if ($member->dependents()->exists()) {
            return $this->parentHouseholdMembers($member);
        }

        return collect([$member]);
    }

    public function memberBelongsToHousehold(Member $member): bool
    {
        $member = $member->fresh() ?? $member;

        return $member->parent_member_id !== null
            || $member->dependents()->exists();
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
