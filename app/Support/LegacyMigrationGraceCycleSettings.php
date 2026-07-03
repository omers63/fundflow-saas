<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

/**
 * Tenant setting for grace cycles used during legacy payment classification and loan import.
 */
final class LegacyMigrationGraceCycleSettings
{
    public const GROUP = 'legacy_migration';

    public const KEY = 'grace_cycles';

    public static function defaultGraceCycles(): int
    {
        return 1;
    }

    /**
     * @return array<int, string>
     */
    public static function graceCycleOptions(): array
    {
        return LoanSettings::graceCycleSelectOptions();
    }

    public static function graceCycles(): int
    {
        $stored = Setting::get(self::GROUP, self::KEY);

        if ($stored === null || $stored === '') {
            return self::defaultGraceCycles();
        }

        return LoanSettings::clampGraceCycles((int) $stored);
    }

    public static function saveGraceCycles(int $graceCycles): void
    {
        Setting::set(self::GROUP, self::KEY, (string) LoanSettings::clampGraceCycles($graceCycles));
    }
}
