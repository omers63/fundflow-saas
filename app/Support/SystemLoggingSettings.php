<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class SystemLoggingSettings
{
    public const GROUP = 'system_logging';

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return [
            'fund_audit_log_enabled' => true,
            'notification_log_enabled' => true,
        ];
    }

    public static function fundAuditLogEnabled(): bool
    {
        return (bool) self::get('fund_audit_log_enabled', true);
    }

    public static function notificationLogEnabled(): bool
    {
        return (bool) self::get('notification_log_enabled', true);
    }

    public static function setFundAuditLogEnabled(bool $enabled): void
    {
        Setting::set(self::GROUP, 'fund_audit_log_enabled', $enabled);
    }

    public static function setNotificationLogEnabled(bool $enabled): void
    {
        Setting::set(self::GROUP, 'notification_log_enabled', $enabled);
    }

    private static function get(string $key, bool $default): bool
    {
        $value = Setting::get(self::GROUP, $key);

        return $value !== null ? (bool) $value : $default;
    }
}
