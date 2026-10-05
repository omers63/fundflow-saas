<?php

declare(strict_types=1);

namespace App\Services\Governance;

use App\Models\Tenant\Meeting;
use App\Models\Tenant\Member;
use App\Models\Tenant\Motion;
use App\Models\Tenant\MotionProxyGrant;
use App\Models\Tenant\User;
use App\Models\Tenant\Vote;
use App\Support\GovernanceSettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MotionService
{
    public function __construct(
        private readonly MotionQuorumService $quorum,
    ) {
    }

    public function createMeeting(string $title, ?User $creator = null, ?string $description = null): Meeting
    {
        return Meeting::query()->create([
            'title' => $title,
            'description' => $description,
            'status' => Meeting::STATUS_DRAFT,
            'created_by' => $creator?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createMotion(
        Meeting $meeting,
        string $title,
        string $type = Motion::TYPE_GENERIC,
        ?string $body = null,
        array $payload = [],
        ?float $amountThreshold = null,
        ?User $creator = null,
    ): Motion {
        if ($meeting->status === Meeting::STATUS_MINUTES_PUBLISHED) {
            throw new InvalidArgumentException(__('Cannot add motions after minutes are published.'));
        }

        if (!array_key_exists($type, Motion::typeLabels())) {
            throw new InvalidArgumentException(__('Invalid motion type.'));
        }

        return Motion::query()->create([
            'meeting_id' => $meeting->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'status' => Motion::STATUS_DRAFT,
            'payload' => $payload,
            'amount_threshold' => $amountThreshold,
            'quorum_percent' => GovernanceSettings::quorumPercent(),
            'eligible_voters' => $this->quorum->eligibleVoterCount(),
            'created_by' => $creator?->id,
        ]);
    }

    public function openMeeting(Meeting $meeting): Meeting
    {
        if (!in_array($meeting->status, [Meeting::STATUS_DRAFT, Meeting::STATUS_CLOSED], true)) {
            throw new InvalidArgumentException(__('Only draft or closed meetings can be opened.'));
        }

        $meeting->forceFill([
            'status' => Meeting::STATUS_OPEN,
            'opened_at' => $meeting->opened_at ?? now(),
            'closed_at' => null,
        ])->save();

        return $meeting->fresh() ?? $meeting;
    }

    public function openMotionForVoting(Motion $motion, ?\DateTimeInterface $closesAt = null): Motion
    {
        if ($motion->status !== Motion::STATUS_DRAFT) {
            throw new InvalidArgumentException(__('Only draft motions can be opened for voting.'));
        }

        $meeting = $motion->meeting;
        if ($meeting !== null && $meeting->status === Meeting::STATUS_DRAFT) {
            $this->openMeeting($meeting);
        }

        $motion->forceFill([
            'status' => Motion::STATUS_OPEN,
            'voting_opens_at' => now(),
            'voting_closes_at' => $closesAt,
            'eligible_voters' => $this->quorum->eligibleVoterCount(),
            'quorum_percent' => GovernanceSettings::quorumPercent(),
        ])->save();

        return $motion->fresh() ?? $motion;
    }

    public function castVote(Motion $motion, Member $member, string $choice): Vote
    {
        if (!$motion->isOpenForVoting()) {
            throw new InvalidArgumentException(__('This motion is not open for voting.'));
        }

        if (!$this->quorum->memberIsEligible($member)) {
            throw new InvalidArgumentException(__('You are not eligible to vote on this motion.'));
        }

        if (!array_key_exists($choice, Vote::choiceLabels())) {
            throw new InvalidArgumentException(__('Invalid vote choice.'));
        }

        return DB::transaction(function () use ($motion, $member, $choice): Vote {
            $vote = Vote::query()->updateOrCreate(
                [
                    'motion_id' => $motion->id,
                    'member_id' => $member->id,
                ],
                [
                    'choice' => $choice,
                    'cast_at' => now(),
                    'cast_by_member_id' => $member->id,
                    'proxy_for_member_id' => null,
                    'proxy_note' => null,
                ],
            );

            $this->refreshTallies($motion);

            return $vote;
        });
    }

    public function grantProxy(Motion $motion, Member $grantor, Member $proxy): MotionProxyGrant
    {
        if (!$motion->isOpenForVoting() && $motion->status !== Motion::STATUS_DRAFT) {
            throw new InvalidArgumentException(__('Proxies can only be granted for draft or open motions.'));
        }

        if ($grantor->id === $proxy->id) {
            throw new InvalidArgumentException(__('You cannot grant a proxy to yourself.'));
        }

        if (!$this->quorum->memberIsEligible($grantor) || !$this->quorum->memberIsEligible($proxy)) {
            throw new InvalidArgumentException(__('Both grantor and proxy must be eligible voters.'));
        }

        return MotionProxyGrant::query()->updateOrCreate(
            [
                'motion_id' => $motion->id,
                'grantor_member_id' => $grantor->id,
            ],
            [
                'proxy_member_id' => $proxy->id,
                'granted_at' => now(),
                'revoked_at' => null,
            ],
        );
    }

    public function revokeProxy(Motion $motion, Member $grantor): void
    {
        MotionProxyGrant::query()
            ->where('motion_id', $motion->id)
            ->where('grantor_member_id', $grantor->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function castProxyVote(Motion $motion, Member $proxy, Member $grantor, string $choice, ?string $note = null): Vote
    {
        if (!$motion->isOpenForVoting()) {
            throw new InvalidArgumentException(__('This motion is not open for voting.'));
        }

        $grant = MotionProxyGrant::query()
            ->where('motion_id', $motion->id)
            ->where('grantor_member_id', $grantor->id)
            ->where('proxy_member_id', $proxy->id)
            ->whereNull('revoked_at')
            ->first();

        if ($grant === null) {
            throw new InvalidArgumentException(__('No active proxy grant found for this voter.'));
        }

        if (!array_key_exists($choice, Vote::choiceLabels())) {
            throw new InvalidArgumentException(__('Invalid vote choice.'));
        }

        return DB::transaction(function () use ($motion, $proxy, $grantor, $choice, $note): Vote {
            $vote = Vote::query()->updateOrCreate(
                [
                    'motion_id' => $motion->id,
                    'member_id' => $grantor->id,
                ],
                [
                    'choice' => $choice,
                    'cast_at' => now(),
                    'cast_by_member_id' => $proxy->id,
                    'proxy_for_member_id' => $grantor->id,
                    'proxy_note' => $note,
                ],
            );

            $this->refreshTallies($motion);

            return $vote;
        });
    }

    public function closeAndResolve(Motion $motion): Motion
    {
        if ($motion->status !== Motion::STATUS_OPEN) {
            throw new InvalidArgumentException(__('Only open motions can be closed.'));
        }

        return DB::transaction(function () use ($motion): Motion {
            $tally = $this->refreshTallies($motion);

            $motion->forceFill([
                'status' => $tally['passed'] ? Motion::STATUS_APPROVED : Motion::STATUS_REJECTED,
                'approved_at' => $tally['passed'] ? now() : null,
                'rejected_at' => $tally['passed'] ? null : now(),
                'voting_closes_at' => $motion->voting_closes_at ?? now(),
            ])->save();

            return $motion->fresh() ?? $motion;
        });
    }

    public function publishMinutes(Meeting $meeting, string $minutes): Meeting
    {
        if (in_array($meeting->status, [Meeting::STATUS_DRAFT], true)) {
            throw new InvalidArgumentException(__('Open or close the meeting before publishing minutes.'));
        }

        $openMotions = $meeting->motions()->where('status', Motion::STATUS_OPEN)->count();
        if ($openMotions > 0) {
            throw new InvalidArgumentException(__('Close all open motions before publishing minutes.'));
        }

        $meeting->forceFill([
            'status' => Meeting::STATUS_MINUTES_PUBLISHED,
            'minutes' => $minutes,
            'minutes_published_at' => now(),
            'closed_at' => $meeting->closed_at ?? now(),
        ])->save();

        return $meeting->fresh() ?? $meeting;
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshTallies(Motion $motion): array
    {
        $tally = $this->quorum->tally($motion);

        $motion->forceFill([
            'yes_count' => $tally['yes'],
            'no_count' => $tally['no'],
            'abstain_count' => $tally['abstain'],
            'eligible_voters' => $tally['eligible'],
            'quorum_met' => $tally['quorum_met'],
        ])->save();

        return $tally;
    }
}
