<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Services\Tenant\HouseholdMemberService;
use App\Support\BusinessDay;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class MembershipApplicationApprovalService
{
    public function __construct(
        private readonly HouseholdMemberService $householdMembers,
        private readonly MembershipSubscriptionFeeService $subscriptionFees,
        private readonly MembershipApprovalPostingPipeline $approvalPostingPipeline,
    ) {}

    public function approve(MembershipApplication $application): Member
    {
        if ($application->status !== 'pending') {
            throw new InvalidArgumentException(__('This application has already been reviewed.'));
        }

        if ($application->parent_application_id !== null) {
            return $this->approveDependent($application);
        }

        if ($application->parent_member_id !== null) {
            return $this->approveOnBehalfDependent($application);
        }

        return $this->approveParent($application);
    }

    /**
     * @param  Collection<int, MembershipApplication>  $applications
     * @return array{
     *     members: list<Member>,
     *     failures: list<array{application_id: int, name: string, message: string}>
     * }
     */
    public function approveMany(Collection $applications): array
    {
        $ordered = $applications
            ->filter(fn (MembershipApplication $application): bool => $application->status === 'pending')
            ->sortBy(fn (MembershipApplication $application): int => $application->parent_application_id === null ? 0 : 1)
            ->values();

        $members = [];
        $failures = [];

        foreach ($ordered as $application) {
            try {
                $members[] = $this->approve($application->fresh());
            } catch (InvalidArgumentException $exception) {
                $failures[] = [
                    'application_id' => (int) $application->id,
                    'name' => (string) $application->name,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'members' => $members,
            'failures' => $failures,
        ];
    }

    private function approveParent(MembershipApplication $application): Member
    {
        $this->subscriptionFees->assertCanApprove($application);

        $member = $this->householdMembers->createFromApplication($application);
        $member = $this->finalizeApprovedApplication($application, $member);

        $this->linkSubmittedDependentsToParent($member);

        return $member;
    }

    private function approveOnBehalfDependent(MembershipApplication $application): Member
    {
        $parentMember = Member::query()->find($application->parent_member_id);

        if ($parentMember === null) {
            throw new RuntimeException(__('Parent member record could not be found.'));
        }

        if (in_array($parentMember->status, Member::PORTAL_BLOCKED_STATUSES, true)) {
            throw new InvalidArgumentException(__('The sponsoring parent member cannot accept dependents while their membership is not active.'));
        }

        $this->subscriptionFees->assertCanApprove($application);

        $member = $this->householdMembers->createFromApplication($application, $parentMember);

        return $this->finalizeApprovedApplication($application, $member);
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

        $this->subscriptionFees->assertCanApprove($application);

        $member = $this->householdMembers->createFromApplication($application, $parentMember);

        return $this->finalizeApprovedApplication($application, $member);
    }

    private function finalizeApprovedApplication(MembershipApplication $application, Member $member): Member
    {
        return $this->approvalPostingPipeline->run($application, $member, BusinessDay::now());
    }

    private function linkSubmittedDependentsToParent(Member $parentMember): void
    {
        $parentUserId = $parentMember->user_id;

        if ($parentUserId === null) {
            return;
        }

        $applications = MembershipApplication::query()
            ->where('submitted_by_user_id', $parentUserId)
            ->whereNull('parent_application_id')
            ->whereNull('parent_member_id')
            ->where('status', 'approved')
            ->whereNotNull('member_id')
            ->get();

        foreach ($applications as $application) {
            $dependentMember = Member::query()->find($application->member_id);

            if ($dependentMember === null || $dependentMember->parent_member_id !== null) {
                continue;
            }

            $this->householdMembers->assignToHousehold(
                $dependentMember,
                $parentMember,
            );

            $application->update(['parent_member_id' => $parentMember->id]);
        }
    }
}
