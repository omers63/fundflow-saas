<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Notifications\Tenant\GenericMemberAlertNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

final class MemberPortalNotificationService
{
    public function send(Member $member, string $title, string $body): bool
    {
        $recipient = $member->user;

        if ($recipient === null) {
            return false;
        }

        $title = trim($title);
        $body = trim($body);

        if ($title === '' || $body === '') {
            return false;
        }

        Notification::send($recipient, new GenericMemberAlertNotification($title, $body));

        return true;
    }

    /**
     * @param  Collection<int, Member>  $members
     * @return array{sent: int, skipped: int}
     */
    public function sendToMany(Collection $members, string $title, string $body): array
    {
        $sent = 0;
        $skipped = 0;

        foreach ($members as $member) {
            if (! $member instanceof Member || ! $this->send($member, $title, $body)) {
                $skipped++;

                continue;
            }

            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
