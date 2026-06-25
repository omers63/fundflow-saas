<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

/**
 * Tenant setting for whether legacy loan import omits the settlement threshold.
 */
final class LegacyMigrationSettlementThresholdSettings
{
    public const GROUP = 'legacy_migration';

    public const KEY = 'skip_settlement_threshold';

    public static function defaultSkipSettlementThreshold(): bool
    {
        return false;
    }

    public static function skipSettlementThreshold(): bool
    {
        $stored = Setting::get(self::GROUP, self::KEY);

        if ($stored === null || $stored === '') {
            return self::defaultSkipSettlementThreshold();
        }

        return filter_var($stored, FILTER_VALIDATE_BOOLEAN);
    }

    public static function saveSkipSettlementThreshold(bool $skip): void
    {
        Setting::set(self::GROUP, self::KEY, $skip ? '1' : '0');
    }
}
