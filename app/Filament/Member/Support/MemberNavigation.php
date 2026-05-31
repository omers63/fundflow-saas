<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

/**
 * Member portal sidebar structure aligned with the legacy FundFlow member panel.
 */
final class MemberNavigation
{
    public const GROUP_MY_FINANCE = 'my_finance';

    public const GROUP_LOANS = 'loans';

    public const GROUP_SETTINGS = 'settings';

    /** Ungrouped items (dashboard uses Filament default -2). */
    public const SORT_MESSAGES = -1;

    public const SORT_CONTRIBUTIONS = 1;

    public const SORT_DEPOSITS = 2;

    public const SORT_CASH_OUTS = 3;

    public const SORT_STATEMENTS = 4;

    public const SORT_DEPENDENTS = 5;

    public const SORT_ACCOUNTS = 6;

    public const SORT_LOANS = 1;

    public const SORT_GUARANTEED_LOANS = 3;

    public const SORT_LOAN_CALCULATOR = 4;

    public const SORT_CONTRIBUTION_SETTINGS = 1;

    public const SORT_NOTIFICATION_PREFERENCES = 2;

    public const SORT_SUPPORT = 3;

    /**
     * @return list<string>
     */
    public static function groupKeys(): array
    {
        return [
            self::GROUP_MY_FINANCE,
            self::GROUP_LOANS,
            self::GROUP_SETTINGS,
        ];
    }

    public static function isGroupKey(string $group): bool
    {
        return in_array($group, self::groupKeys(), true);
    }

    public static function groupLabel(string $key): string
    {
        return match ($key) {
            self::GROUP_MY_FINANCE => __('My Finance'),
            self::GROUP_LOANS => __('My Loans'),
            self::GROUP_SETTINGS => __('Settings'),
            default => $key,
        };
    }
}
