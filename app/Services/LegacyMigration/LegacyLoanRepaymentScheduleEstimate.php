<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;

/**
 * Estimates legacy EMI schedule shape for payment classification when no
 * imported loan installments exist in the database yet.
 */
final class LegacyLoanRepaymentScheduleEstimate
{
    /**
     * @return array{installment_amount: float, installment_count: int}
     */
    public static function forWindow(LegacyLoanRepaymentWindow $window): array
    {
        $minInstall = self::minimumInstallmentAmount($window->amountApproved);

        if ($window->installmentsCount !== null && $window->installmentsCount > 0) {
            return [
                'installment_amount' => $minInstall,
                'installment_count' => $window->installmentsCount,
            ];
        }

        $memberPortion = LoanSettings::resolveFundingPortions(
            $window->amountApproved,
            0,
            LoanFundingStrategy::SPLIT_PERCENTAGE,
        )['member_portion'];

        $count = max(1, (int) floor($memberPortion / max(1, $minInstall)));

        return [
            'installment_amount' => $minInstall,
            'installment_count' => $count,
        ];
    }

    public static function minimumInstallmentAmount(float $amountApproved): float
    {
        return (float) (LoanTier::forAmount($amountApproved)?->min_monthly_installment ?? 1000);
    }
}
