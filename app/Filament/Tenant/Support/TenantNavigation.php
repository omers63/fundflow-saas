<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use Filament\Navigation\NavigationGroup;

/**
 * Tenant admin sidebar groups and sort order.
 */
final class TenantNavigation
{
    public const GROUP_ACCOUNTS = 'Accounts';

    public const GROUP_FUND_MANAGEMENT = 'Fund Management';

    public const GROUP_SYSTEM = 'System';

    /** Ungrouped items: Dashboard (Filament default -2), then Messages. */
    public const SORT_MESSAGES = -1;

    public const SORT_APPLICATIONS = 1;

    public const SORT_MEMBERS = 2;

    public const SORT_DEPOSITS = 3;

    public const SORT_CASH_OUTS = 4;

    public const SORT_CONTRIBUTIONS = 5;

    public const SORT_LOANS = 6;

    public const SORT_STATEMENTS = 7;

    /** Last item in Fund Management (after statements, loans cluster, etc.). */
    public const SORT_RECONCILIATION = 8;

    public const SORT_JOBS = 1;

    public const SORT_AUDIT_LOGS = 3;

    public const SORT_LOAN_OVERRIDES = 4;

    public const SORT_MIGRATIONS = 5;

    public const SORT_SETTINGS = 6;

    /**
     * @return list<string>
     */
    public static function groupKeys(): array
    {
        return [
            self::GROUP_ACCOUNTS,
            self::GROUP_FUND_MANAGEMENT,
            self::GROUP_SYSTEM,
        ];
    }

    public static function isGroupKey(string $group): bool
    {
        return in_array($group, self::groupKeys(), true);
    }

    public static function groupLabel(string $key): string
    {
        return match ($key) {
            self::GROUP_ACCOUNTS => __('Accounts'),
            self::GROUP_FUND_MANAGEMENT => __('Fund Management'),
            self::GROUP_SYSTEM => __('System'),
            default => $key,
        };
    }

    /**
     * Keys must match {@see static::$navigationGroup} on resources/pages so Filament can order groups.
     * Numeric array keys break ordering (PHP loose `array_search`: `'System' == 0`).
     *
     * @return array<string, NavigationGroup>
     */
    public static function navigationGroups(): array
    {
        return [
            self::GROUP_ACCOUNTS => NavigationGroup::make()
                ->label(fn (): string => self::groupLabel(self::GROUP_ACCOUNTS)),
            self::GROUP_FUND_MANAGEMENT => NavigationGroup::make()
                ->label(fn (): string => self::groupLabel(self::GROUP_FUND_MANAGEMENT)),
            self::GROUP_SYSTEM => NavigationGroup::make()
                ->label(fn (): string => self::groupLabel(self::GROUP_SYSTEM)),
        ];
    }
}
