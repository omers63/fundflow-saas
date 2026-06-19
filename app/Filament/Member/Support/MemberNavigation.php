<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\Loan;
use Illuminate\Database\Eloquent\Builder;

/**
 * Member portal sidebar structure aligned with the member-portal prototype.
 */
final class MemberNavigation
{
    public const GROUP_MY_ACCOUNTS = 'my_accounts';

    public const GROUP_LOANS = 'loans';

    public const GROUP_HISTORY = 'history';

    public const GROUP_SELF_SERVICE = 'self_service';

    /** Ungrouped items (dashboard uses Filament default -2). */
    public const SORT_MESSAGES = -1;

    public const SORT_CASH_ACCOUNT = 1;

    public const SORT_FUND_ACCOUNT = 2;

    public const SORT_LOANS = 1;

    public const SORT_REQUEST_LOAN = 2;

    public const SORT_GUARANTEED_LOANS = 3;

    public const SORT_LOAN_CALCULATOR = 4;

    public const SORT_CONTRIBUTIONS = 1;

    public const SORT_ACTIVITY = 2;

    public const SORT_CASH_OUTS = 1;

    public const SORT_STATEMENTS = 2;

    public const SORT_DEPOSITS = 3;

    public const SORT_DEPENDENTS = 4;

    public const SORT_SETTINGS = 5;

    public const SORT_HELP = 6;

    /** Legacy hidden pages — group/sort retained for redirects and tests. */
    public const SORT_ACCOUNTS = 6;

    public const SORT_CONTRIBUTION_SETTINGS = 1;

    public const SORT_NOTIFICATION_PREFERENCES = 2;

    public const SORT_SUPPORT = 3;

    public const SORT_BUSINESS_DAY_TEST = 4;

    /**
     * @return list<string>
     */
    public static function groupKeys(): array
    {
        return [
            self::GROUP_MY_ACCOUNTS,
            self::GROUP_LOANS,
            self::GROUP_HISTORY,
            self::GROUP_SELF_SERVICE,
        ];
    }

    public static function isGroupKey(string $group): bool
    {
        return in_array($group, self::groupKeys(), true);
    }

    public static function groupLabel(string $key): string
    {
        return match ($key) {
            self::GROUP_MY_ACCOUNTS => __('My Accounts'),
            self::GROUP_LOANS => __('Loans'),
            self::GROUP_HISTORY => __('History'),
            self::GROUP_SELF_SERVICE => __('Self-Service'),
            default => $key,
        };
    }

    public static function unreadAdminMessageCount(): int
    {
        $userId = auth('tenant')->id();

        if ($userId === null) {
            return 0;
        }

        return (int) DirectMessage::query()
            ->where('to_user_id', $userId)
            ->whereNull('read_at')
            ->whereHas('sender', fn (Builder $query) => $query->where('is_admin', true))
            ->count();
    }

    public static function activeLoanCount(): int
    {
        $memberId = auth('tenant')->user()?->member?->id;

        if ($memberId === null) {
            return 0;
        }

        return (int) Loan::query()
            ->where('member_id', $memberId)
            ->where('status', 'active')
            ->count();
    }
}
