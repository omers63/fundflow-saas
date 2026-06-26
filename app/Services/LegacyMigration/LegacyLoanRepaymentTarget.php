<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;

/**
 * Fund-portion repayment target for legacy migration: master slice plus settlement
 * obligation ({@see Loan::fullRepaymentThreshold()}).
 */
final class LegacyLoanRepaymentTarget
{
    public const float AMOUNT_TOLERANCE = 0.02;

    public static function forLoan(Loan $loan): float
    {
        $masterPortion = (float) $loan->master_portion;
        $approved = (float) ($loan->amount_approved ?? $loan->amount);
        $settlement = (float) ($loan->settlement_threshold ?? 0);

        if ($masterPortion > self::AMOUNT_TOLERANCE || $settlement > self::AMOUNT_TOLERANCE) {
            return round($masterPortion + ($approved * $settlement), 2);
        }

        return self::estimateFromApprovedAmount($approved, $settlement);
    }

    /**
     * @param  array<string, string>  $csvRow
     */
    public static function fromLoansCsvRow(array $csvRow, float $amountApproved): float
    {
        $masterCell = trim((string) ($csvRow['master_portion'] ?? ''));
        $settlementCell = trim((string) ($csvRow['settlement_threshold'] ?? ''));

        if ($masterCell !== '' && is_numeric($masterCell)) {
            $settlement = $settlementCell !== '' && is_numeric($settlementCell)
                ? (float) $settlementCell
                : 0.0;

            return round((float) $masterCell + ($amountApproved * $settlement), 2);
        }

        $settlement = $settlementCell !== '' && is_numeric($settlementCell)
            ? (float) $settlementCell
            : 0.0;

        return self::estimateFromApprovedAmount($amountApproved, $settlement);
    }

    public static function estimateFromApprovedAmount(float $amountApproved, float $settlementThreshold = 0.0): float
    {
        $portions = LoanSettings::resolveFundingPortions(
            $amountApproved,
            0,
            LoanFundingStrategy::SPLIT_PERCENTAGE,
        );

        return round($portions['master_portion'] + ($amountApproved * $settlementThreshold), 2);
    }

    /**
     * Remaining fund-portion obligation for a cumulative repayment total.
     */
    public static function remainingFundPortionObligation(float $target, float $cumulativeRepaid): float
    {
        return max(0.0, round($target - $cumulativeRepaid, 2));
    }

    public static function fundPortionSatisfied(float $target, float $cumulativeRepaid, float $installmentAmount = 0.0): bool
    {
        if ($cumulativeRepaid + self::AMOUNT_TOLERANCE < $target) {
            return false;
        }

        if ($installmentAmount <= self::AMOUNT_TOLERANCE) {
            return true;
        }

        return $cumulativeRepaid <= $target + $installmentAmount + self::AMOUNT_TOLERANCE;
    }

    public static function hasRemainingFundPortion(float $target, float $cumulativeRepaid): bool
    {
        return $cumulativeRepaid + self::AMOUNT_TOLERANCE < $target;
    }

    /**
     * @deprecated Use {@see forLoan()} or {@see estimateFromApprovedAmount()}.
     */
    public static function totalRepaymentDue(float $amountApproved): float
    {
        return self::estimateFromApprovedAmount($amountApproved);
    }
}
