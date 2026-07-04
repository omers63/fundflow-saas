<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use Filament\Navigation\NavigationGroup;

/**
 * Tenant admin sidebar groups and sort order.
 */
final class TenantNavigation
{
    /** Finance group (bank, master accounts, reconciliation, reports). */
    public const GROUP_ACCOUNTS = 'Finance';

    /** Operations group (members, loans, collections, deposits, applications). */
    public const GROUP_FUND_MANAGEMENT = 'Operations';

    public const GROUP_SYSTEM = 'System';

    /** Consolidated sidebar — Operations */
    public const SORT_MEMBERS = 10;

    public const SORT_LOANS = 20;

    public const SORT_CONTRIBUTIONS = 30;

    public const SORT_DISBURSEMENTS = 40;

    public const SORT_DEPOSITS = 42;

    public const SORT_CASH_OUTS = 45;

    /** Consolidated sidebar — Finance */
    public const SORT_BANK_CLEARING = 10;

    public const SORT_SMS_IMPORTS = 15;

    public const SORT_TRANSACTIONS = 18;

    public const SORT_RECONCILIATION = 20;

    public const SORT_REPORTS = 30;

    /** Consolidated sidebar — System */
    public const SORT_AUDIT_SYSTEM = 10;

    public const SORT_SETTINGS = 20;

    /** Hidden from sidebar (legacy sort constants). */
    public const SORT_MESSAGES = -1;

    public const SORT_APPLICATIONS = 901;

    public const SORT_MEMBER_REQUESTS = 902;

    public const SORT_SUPPORT_REQUESTS = 903;

    public const SORT_STATEMENTS = 906;

    public const SORT_JOBS = 907;

    public const SORT_SYSTEM_MAINTENANCE = 908;

    public const SORT_AUDIT_LOGS = 909;

    public const SORT_LOAN_OVERRIDES = 910;

    public const SORT_MIGRATIONS = 911;

    public const SORT_NOTIFICATION_LOGS = 912;

    /**
     * @return list<string>
     */
    public static function groupKeys(): array
    {
        return [
            self::GROUP_FUND_MANAGEMENT,
            self::GROUP_ACCOUNTS,
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
            self::GROUP_ACCOUNTS => __('Finance'),
            self::GROUP_FUND_MANAGEMENT => __('Operations'),
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
            self::GROUP_FUND_MANAGEMENT => NavigationGroup::make()
                ->label(fn (): string => self::groupLabel(self::GROUP_FUND_MANAGEMENT)),
            self::GROUP_ACCOUNTS => NavigationGroup::make()
                ->label(fn (): string => self::groupLabel(self::GROUP_ACCOUNTS)),
            self::GROUP_SYSTEM => NavigationGroup::make()
                ->label(fn (): string => self::groupLabel(self::GROUP_SYSTEM)),
        ];
    }
}
