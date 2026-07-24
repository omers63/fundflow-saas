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

    /**
     * Absolute asset URL so Android reliably loads the status-bar badge
     * (relative paths often fall back to Chrome's default bell icon).
     */
    public static function badgeUrl(): string
    {
        return url(self::BADGE_PATH);
    }

    public static function iconUrl(): string
    {
        return url(self::ICON_PATH);
    }
}
