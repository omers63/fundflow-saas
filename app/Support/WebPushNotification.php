<?php

declare(strict_types=1);

namespace App\Support;

final class WebPushNotification
{
    /** Monochrome silhouette for Android status bar (transparent background). */
    public const BADGE_PATH = '/icons/notification-badge-96x96.png';

    /** Full-color logo for the notification drawer (transparent background). */
    public const ICON_PATH = '/icons/notification-icon-192x192.png';

    /** Keep UTF-8 push JSON under the ~4KB Web Push limit. */
    public const MAX_TITLE_CHARS = 80;

    public const MAX_BODY_CHARS = 160;

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
        if (tenant() !== null) {
            return BrandAppearanceSettings::notificationBadgeUrl();
        }

        return AppBrand::iconUrl('notification_badge');
    }

    public static function iconUrl(): string
    {
        if (tenant() !== null) {
            return BrandAppearanceSettings::notificationIconUrl();
        }

        return AppBrand::iconUrl('notification_icon');
    }

    public static function truncate(string $text, int $maxChars): string
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(1, $maxChars - 1))).'…';
    }
}
