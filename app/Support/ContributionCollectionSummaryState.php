<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;

/**
 * Contribution cycle summary states for admin exports and collection views.
 */
final class ContributionCollectionSummaryState
{
    public const DUE = 'due';

    public const PAID = 'paid';

    public const GRACE_EXEMPT = 'grace-exempt';

    public const EMI_EXEMPT = 'emi-exempt';

    public static function resolve(Member $member, int $month, int $year, ?Contribution $contribution): string
    {
        if ($member->isInLoanGracePeriodForCycle($month, $year)) {
            return self::GRACE_EXEMPT;
        }

        if ($member->isInActiveLoanContributionExemptCycle($month, $year)) {
            return self::EMI_EXEMPT;
        }

        if ($contribution?->status === 'posted') {
            return self::PAID;
        }

        $collectionStatus = $contribution?->collection_status;

        if ($collectionStatus === null || $collectionStatus === ContributionCollectionStatus::PENDING) {
            return self::DUE;
        }

        if ($collectionStatus === ContributionCollectionStatus::COLLECTED) {
            return self::PAID;
        }

        return (string) $collectionStatus;
    }

    public static function isExcludedFromSummaryExport(Member $member, int $month, int $year): bool
    {
        return in_array(self::resolve($member, $month, $year, null), [
            self::GRACE_EXEMPT,
            self::EMI_EXEMPT,
        ], true);
    }
}
