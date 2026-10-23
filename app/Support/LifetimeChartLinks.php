<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Member\Pages\MemberActivityPage;
use App\Filament\Tenant\Resources\Transactions\TransactionResource;
use App\Models\Tenant\Member;
use App\Services\MemberActivityFeedService;
use App\Support\TransactionBusinessTypeCatalog;

/**
 * Where a click on a lifetime-chart bar goes: the member's transactions of that kind (contribution / repayment /
 * disbursement) for the date window of the bar. URLs carry __FROM__ / __UNTIL__ placeholders the chart fills in.
 */
final class LifetimeChartLinks
{
    public const FROM = '__FROM__';

    public const UNTIL = '__UNTIL__';

    /**
     * Admin: the Transactions list filtered by member, transaction type and date.
     *
     * @return array<string, string>
     */
    public static function forAdmin(Member $member): array
    {
        $types = [
            'contribution' => TransactionBusinessTypeCatalog::CONTRIBUTION,
            'repayment' => TransactionBusinessTypeCatalog::EMI,
            'disbursement' => TransactionBusinessTypeCatalog::LOAN,
        ];

        return array_map(fn (string $type): string => TransactionResource::getUrl('index', ['filters' => [
            'member_id' => ['value' => $member->getKey()],
            'business_type' => ['value' => $type],
            'date_range_transacted_at' => ['from' => self::FROM, 'until' => self::UNTIL],
        ]]), $types);
    }

    /**
     * Member portal: the Transactions page with the matching chip and date range.
     *
     * @return array<string, string>
     */
    public static function forMemberPortal(): array
    {
        $chips = [
            'contribution' => MemberActivityFeedService::FILTER_CONTRIBUTIONS,
            'repayment' => MemberActivityFeedService::FILTER_EMI,
            'disbursement' => MemberActivityFeedService::FILTER_LOAN_EVENTS,
        ];

        return array_map(fn (string $chip): string => MemberActivityPage::getUrl([
            'filter' => $chip,
            'filters' => ['date_range_transacted_at' => ['from' => self::FROM, 'until' => self::UNTIL]],
        ]), $chips);
    }
}
