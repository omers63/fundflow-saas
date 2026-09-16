<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Models\Tenant\Setting;

final class SecuritySettings
{
    public const GROUP = 'security';

    public static function enforceAdminTwoFactor(): bool
    {
        return (string) Setting::get(self::GROUP, 'enforce_admin_2fa', '0') === '1';
    }

    public static function setEnforceAdminTwoFactor(bool $enabled): void
    {
        Setting::set(self::GROUP, 'enforce_admin_2fa', $enabled ? '1' : '0');
    }

    /**
     * @return array{enforce_admin_2fa: string}
     */
    public static function defaults(): array
    {
        return [
            'enforce_admin_2fa' => '0',
        ];
    }
}
