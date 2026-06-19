<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\User;

/**
 * Reference-counted locale switch for multi-channel notification sends.
 */
final class MemberNotificationLocale
{
    private static int $depth = 0;

    private static ?string $savedLocale = null;

    public static function enter(User $user): void
    {
        if (self::$depth === 0) {
            self::$savedLocale = app()->getLocale();
            app()->setLocale($user->preferredLocale());
        }

        self::$depth++;
    }

    public static function leave(): void
    {
        if (self::$depth <= 0) {
            return;
        }

        self::$depth--;

        if (self::$depth === 0 && self::$savedLocale !== null) {
            app()->setLocale(self::$savedLocale);
            self::$savedLocale = null;
        }
    }

    public static function reset(): void
    {
        self::$depth = 0;
        self::$savedLocale = null;
    }
}
