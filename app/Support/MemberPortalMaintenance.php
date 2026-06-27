<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\FundAuditLogService;
use Illuminate\Http\Request;

final class MemberPortalMaintenance
{
    public const GROUP = 'member_portal';

    public const ENABLED_KEY = 'maintenance_enabled';

    public const MESSAGE_KEY = 'maintenance_message';

    public const EPOCH_KEY = 'maintenance_epoch';

    public const SESSION_EPOCH_KEY = 'member_portal_maintenance_epoch';

    public const MAINTENANCE_NOTICE_SESSION_KEY = 'member_maintenance_notice';

    public static function isEnabled(): bool
    {
        return (bool) Setting::get(self::GROUP, self::ENABLED_KEY, false);
    }

    public static function epoch(): int
    {
        return (int) Setting::get(self::GROUP, self::EPOCH_KEY, 0);
    }

    public static function storedMessage(): ?string
    {
        $message = Setting::get(self::GROUP, self::MESSAGE_KEY);

        if (! is_string($message)) {
            return null;
        }

        $trimmed = trim($message);

        return $trimmed !== '' ? $trimmed : null;
    }

    public static function message(): string
    {
        return self::storedMessage() ?? __(
            'System under maintenance. Member portal sign-in is temporarily unavailable. Please try again later.',
        );
    }

    public static function enable(?string $message = null): void
    {
        $nextEpoch = self::epoch() + 1;

        Setting::set(self::GROUP, self::ENABLED_KEY, true);
        Setting::set(self::GROUP, self::MESSAGE_KEY, filled($message) ? trim((string) $message) : null);
        Setting::set(self::GROUP, self::EPOCH_KEY, $nextEpoch);

        app(FundAuditLogService::class)->log(
            'MEMBER_PORTAL_MAINTENANCE_ENABLED',
            'system',
            payload: [
                'epoch' => $nextEpoch,
                'message' => self::storedMessage(),
            ],
        );
    }

    public static function disable(): void
    {
        Setting::set(self::GROUP, self::ENABLED_KEY, false);
        Setting::set(self::GROUP, self::MESSAGE_KEY, null);

        app(FundAuditLogService::class)->log(
            'MEMBER_PORTAL_MAINTENANCE_DISABLED',
            'system',
        );
    }

    public static function updateMessage(?string $message): void
    {
        if (! self::isEnabled()) {
            return;
        }

        Setting::set(self::GROUP, self::MESSAGE_KEY, filled($message) ? trim((string) $message) : null);
    }

    public static function syncSessionEpoch(): void
    {
        session()->put(self::SESSION_EPOCH_KEY, self::epoch());
    }

    public static function sessionEpochIsValid(): bool
    {
        if (! self::isEnabled()) {
            return true;
        }

        $sessionEpoch = session(self::SESSION_EPOCH_KEY);

        if (! is_int($sessionEpoch) && ! is_string($sessionEpoch)) {
            return false;
        }

        return (int) $sessionEpoch >= self::epoch();
    }

    public static function isExempt(?Request $request = null): bool
    {
        $impersonatorId = self::impersonatorUserIdFromSession($request);

        if ($impersonatorId <= 0) {
            return false;
        }

        $impersonator = User::query()->find($impersonatorId);

        return $impersonator?->is_admin === true;
    }

    private static function impersonatorUserIdFromSession(?Request $request = null): int
    {
        if ($request?->hasSession()) {
            return (int) $request->session()->get('impersonator_user_id');
        }

        return (int) session('impersonator_user_id', 0);
    }

    public static function shouldBlockMemberPortalAccess(Request $request): bool
    {
        if (! self::isEnabled()) {
            return false;
        }

        if (self::isExempt($request)) {
            return false;
        }

        return ! self::sessionEpochIsValid();
    }
}
