<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\MemberOnboardingGreetingNotification;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MemberOnboardingGreetingService
{
    /**
     * @return array{notified: int, skipped: int}
     */
    public function sendToActiveMembers(?int $memberId = null): array
    {
        $notified = 0;
        $skipped = 0;

        $query = Member::query()
            ->active()
            ->with('user')
            ->orderBy('id');

        if ($memberId !== null) {
            $query->whereKey($memberId);
        }

        $query->each(function (Member $member) use (&$notified, &$skipped): void {
            if ($this->sendToMember($member)) {
                $notified++;

                return;
            }

            $skipped++;
        });

        return [
            'notified' => $notified,
            'skipped' => $skipped,
        ];
    }

    public function sendToMember(Member $member, ?string $plainPassword = null): bool
    {
        $member->loadMissing('user');
        $user = $member->user;

        if ($user === null || blank($user->email)) {
            return false;
        }

        if ($this->alreadySent($member, $user)) {
            return false;
        }

        try {
            $user->notify(new MemberOnboardingGreetingNotification($member, $plainPassword));
            $this->markSent($member);

            return true;
        } catch (Throwable $exception) {
            Log::warning('MemberOnboardingGreetingService: failed to notify member', [
                'member_id' => $member->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function alreadySent(Member $member, User $user): bool
    {
        if ($member->onboarding_greeting_sent_at !== null) {
            return true;
        }

        if (! Schema::hasTable('notifications')) {
            return false;
        }

        $exists = $user->notifications()
            ->where('type', MemberOnboardingGreetingNotification::class)
            ->exists();

        if ($exists) {
            $this->markSent($member);
        }

        return $exists;
    }

    private function markSent(Member $member): void
    {
        $member->forceFill([
            'onboarding_greeting_sent_at' => BusinessDay::now(),
        ])->save();
    }
}
