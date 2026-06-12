<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

/**
 * 50/50 fund allocation: member repays 50% of principal plus 16% pool fee on the full loan.
 */
final class LegacyLoanRepaymentTarget
{
    public const MEMBER_PORTION_RATE = 0.5;

    public const POOL_FEE_RATE = 0.16;

    public static function totalRepaymentDue(float $amountApproved): float
    {
        return round(
            ($amountApproved * self::MEMBER_PORTION_RATE) + ($amountApproved * self::POOL_FEE_RATE),
            2,
        );
    }
}
