<?php

declare(strict_types=1);

namespace App\Support;

use NotificationChannels\WebPush\WebPushChannel;

final class AdminNotificationChannels
{
    /**
     * @return list<string|class-string>
     */
    public static function resolve(): array
    {
        $channels = ['database'];

        if (WebPushNotification::enabled()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }
}
