<?php

declare(strict_types=1);

namespace App\Support\Security;

final class TwoFactorSession
{
    public const PENDING_USER_ID = 'two_factor_pending_user_id';

    public const PENDING_REMEMBER = 'two_factor_pending_remember';

    public const PASSED = 'two_factor_passed';

    public static function markPassed(): void
    {
        session([self::PASSED => true]);
    }

    public static function hasPassed(): bool
    {
        return (bool) session(self::PASSED, false);
    }

    public static function clear(): void
    {
        session()->forget([self::PENDING_USER_ID, self::PENDING_REMEMBER, self::PASSED]);
    }

    public static function stashPending(int $userId, bool $remember): void
    {
        session([
            self::PENDING_USER_ID => $userId,
            self::PENDING_REMEMBER => $remember,
            self::PASSED => false,
        ]);
    }
}
