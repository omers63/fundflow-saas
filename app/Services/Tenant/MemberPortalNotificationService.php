<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Filament\Support\MemberDatabaseNotification;
use App\Models\Tenant\Member;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

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

        MemberDatabaseNotification::send($recipient, function (Notification $notification) use ($title, $body): void {
            $notification
                ->title($title)
                ->body($body)
                ->icon('heroicon-o-bell')
                ->iconColor('info');
        });

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
