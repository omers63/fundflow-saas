<?php

declare(strict_types=1);

namespace App\Support;

final class WebPushNotification
{
    /** Monochrome silhouette for Android status bar (transparent background). */
    public const BADGE_PATH = '/icons/notification-badge-96x96.png';

    /** Full-color logo for the notification drawer (transparent background). */
    public const ICON_PATH = '/icons/notification-icon-192x192.png';

    public static function enabled(): bool
    {
        return filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }
}
