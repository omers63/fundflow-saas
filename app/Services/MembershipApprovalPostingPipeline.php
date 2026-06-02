<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use Carbon\CarbonInterface;

final class MembershipApprovalPostingPipeline
{
    public function __construct(
        private readonly MembershipSubscriptionFeeService $subscriptionFees,
        private readonly MembershipApplicationImportCutoffService $importCutoffs,
    ) {
    }

    public function run(
        MembershipApplication $application,
        Member $member,
        ?CarbonInterface $reviewedAt = null,
    ): Member {
        $approvedApplication = $this->markApplicationApproved($application, $member, $reviewedAt);

        $this->prepareImportCutoffIfNeeded($approvedApplication, $member);
        $this->postSubscriptionFee($approvedApplication, $member);
        $this->postOpeningBalances($approvedApplication, $member);

        return $member->fresh();
    }

    private function markApplicationApproved(
        MembershipApplication $application,
        Member $member,
        ?CarbonInterface $reviewedAt = null,
    ): MembershipApplication {
        $application->update([
            'status' => 'approved',
            'reviewed_at' => $reviewedAt ?? now(),
            'member_id' => $member->id,
            'household_email' => $member->household_email,
        ]);

        return $application->fresh();
    }

    private function prepareImportCutoffIfNeeded(MembershipApplication $application, Member $member): void
    {
        if (!$application->wasImportedFromCsv()) {
            return;
        }

        $this->importCutoffs->prepareCutoffOnApproval($application, $member);
    }

    private function postSubscriptionFee(MembershipApplication $application, Member $member): void
    {
        $this->subscriptionFees->postOnApproval($application, $member);
    }

    private function postOpeningBalances(MembershipApplication $application, Member $member): void
    {
        $this->importCutoffs->postOpeningBalancesOnApproval($application, $member);
    }
}
