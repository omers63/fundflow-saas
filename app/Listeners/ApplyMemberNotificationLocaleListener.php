<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Tenant\User;
use App\Support\MemberNotificationLocale;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;

final class ApplyMemberNotificationLocaleListener
{
    public function handleSending(NotificationSending $event): void
    {
        if ($event->notifiable instanceof User) {
            MemberNotificationLocale::enter($event->notifiable);
        }
    }

    public function handleSent(NotificationSent $event): void
    {
        if ($event->notifiable instanceof User) {
            MemberNotificationLocale::leave();
        }
    }

    public function handleFailed(NotificationFailed $event): void
    {
        if ($event->notifiable instanceof User) {
            MemberNotificationLocale::leave();
        }
    }
}
