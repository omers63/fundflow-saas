<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Services\Tenant\HouseholdMemberService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class MembershipApplicationApprovalService
{
    public function __construct(
        private readonly HouseholdMemberService $householdMembers,
    ) {}

    public function approve(MembershipApplication $application): Member
    {
        if ($application->status !== 'pending') {
            throw new InvalidArgumentException(__('This application has already been reviewed.'));
        }

        if ($application->parent_application_id !== null) {
            return $this->approveDependent($application);
        }

        return $this->approveParent($application);
    }

    /**
     * @param  Collection<int, MembershipApplication>  $applications
     * @return list<Member>
     */
    public function approveMany(Collection $applications): array
    {
        $ordered = $applications
            ->filter(fn (MembershipApplication $application): bool => $application->status === 'pending')
            ->sortBy(fn (MembershipApplication $application): int => $application->parent_application_id === null ? 0 : 1)
            ->values();

        $members = [];

        foreach ($ordered as $application) {
            $members[] = $this->approve($application->fresh());
        }

        return $members;
    }

    private function approveParent(MembershipApplication $application): Member
    {
        $member = $this->householdMembers->createFromApplication($application);

        $application->update([
            'status' => 'approved',
            'reviewed_at' => now(),
            'member_id' => $member->id,
            'household_email' => $member->household_email,
        ]);

        return $member;
    }

    private function approveDependent(MembershipApplication $application): Member
    {
        $parentApplication = $application->parentApplication;

        if ($parentApplication === null) {
            throw new RuntimeException(__('Parent application could not be found.'));
        }

        if ($parentApplication->status === 'pending') {
            $this->approveParent($parentApplication->fresh());
            $parentApplication = $parentApplication->fresh();
        }

        if ($parentApplication->status !== 'approved' || $parentApplication->member_id === null) {
            throw new RuntimeException(__('Parent application must be approved before approving a dependent.'));
        }

        $parentMember = Member::query()->find($parentApplication->member_id);

        if ($parentMember === null) {
            throw new RuntimeException(__('Parent member record could not be found.'));
        }

        $member = $this->householdMembers->createFromApplication($application, $parentMember);

        $application->update([
            'status' => 'approved',
            'reviewed_at' => now(),
            'member_id' => $member->id,
            'household_email' => $member->household_email,
        ]);

        return $member;
    }
}
