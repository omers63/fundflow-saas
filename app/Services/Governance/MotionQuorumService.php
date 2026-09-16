<?php

declare(strict_types=1);

namespace App\Services\Governance;

use App\Models\Tenant\Member;
use App\Models\Tenant\Motion;
use App\Models\Tenant\Vote;
use App\Support\GovernanceSettings;

final class MotionQuorumService
{
    /**
     * @return array{
     *     eligible: int,
     *     cast: int,
     *     yes: int,
     *     no: int,
     *     abstain: int,
     *     quorum_percent: float,
     *     quorum_met: bool,
     *     passed: bool
     * }
     */
    public function tally(Motion $motion): array
    {
        $eligible = $this->eligibleVoterCount();
        $yes = (int) $motion->votes()->where('choice', Vote::CHOICE_YES)->count();
        $no = (int) $motion->votes()->where('choice', Vote::CHOICE_NO)->count();
        $abstain = (int) $motion->votes()->where('choice', Vote::CHOICE_ABSTAIN)->count();
        $cast = $yes + $no + $abstain;
        $quorumPercent = (float) ($motion->quorum_percent ?? GovernanceSettings::quorumPercent());
        $required = (int) ceil($eligible * ($quorumPercent / 100));
        $quorumMet = $eligible === 0 ? false : $cast >= $required;
        $passed = $quorumMet && $yes > $no;

        return [
            'eligible' => $eligible,
            'cast' => $cast,
            'yes' => $yes,
            'no' => $no,
            'abstain' => $abstain,
            'quorum_percent' => $quorumPercent,
            'quorum_met' => $quorumMet,
            'passed' => $passed,
        ];
    }

    public function eligibleVoterCount(): int
    {
        if (GovernanceSettings::boardOnlyVoting()) {
            return (int) Member::query()
                ->where('status', 'active')
                ->whereHas('user', fn ($q) => $q->where('is_admin', true))
                ->count();
        }

        return (int) Member::query()->where('status', 'active')->count();
    }

    public function memberIsEligible(Member $member): bool
    {
        if ($member->status !== 'active') {
            return false;
        }

        if (! GovernanceSettings::boardOnlyVoting()) {
            return true;
        }

        return (bool) $member->user?->is_admin;
    }
}
