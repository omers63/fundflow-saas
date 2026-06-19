<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\User;

final class MemberLocale
{
    /**
     * Run a callback using the member's effective locale for this request, then restore the previous locale.
     *
     * Prefers the locale already active on the request (Filament / language switch) so PDFs and
     * exports match what the member is viewing. Falls back to {@see User::preferredLocale()}.
     */
    public static function using(User $user, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale(self::forRequest($user));

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * Run a callback using the recipient's saved locale (for outbound notifications).
     */
    public static function usingPreferred(User $user, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($user->preferredLocale());

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    public static function forRequest(User $user): string
    {
        $requestLocale = app()->getLocale();

        if (AppLocale::isSupported($requestLocale)) {
            return $requestLocale;
        }

        return $user->preferredLocale();
    }
}
