<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Notifications\Tenant\MemberOnboardingGreetingNotification;
use Illuminate\Support\Facades\Log;
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

    public function sendToMember(Member $member): bool
    {
        $member->loadMissing('user');
        $user = $member->user;

        if ($user === null || blank($user->email)) {
            return false;
        }

        try {
            $user->notify(new MemberOnboardingGreetingNotification($member));

            return true;
        } catch (Throwable $exception) {
            Log::warning('MemberOnboardingGreetingService: failed to notify member', [
                'member_id' => $member->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
