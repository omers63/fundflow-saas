<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use Carbon\Carbon;

/**
 * Loan repayment window used by the legacy payment classifier.
 */
final readonly class LegacyLoanRepaymentWindow
{
    public function __construct(
        public string $loanKey,
        public Carbon $disbursedAt,
        public float $amountApproved,
        public float $repaymentTargetAmount,
        public ?int $loanId = null,
    ) {
    }

    public static function loanKey(string $memberNumber, Carbon $disbursedAt): string
    {
        return trim($memberNumber) . '|' . $disbursedAt->toDateString();
    }

    public function isDisbursedOnOrBefore(Carbon $paymentDate): bool
    {
        return $paymentDate->gte($this->disbursedAt);
    }

    public function hasRemainingRepayment(float $cumulativeRepaid): bool
    {
        return $cumulativeRepaid + 0.00001 < $this->repaymentTargetAmount;
    }

    public function isRepaymentWindowClosed(float $cumulativeRepaid): bool
    {
        return !$this->hasRemainingRepayment($cumulativeRepaid);
    }

    public function remainingRepayment(float $cumulativeRepaid): float
    {
        return max(0.0, round($this->repaymentTargetAmount - $cumulativeRepaid, 2));
    }

    /**
     * Returns the earliest disbursed loan window that still accepts repayments.
     * Later loans stay closed until every prior window is repaid in full.
     *
     * @param  iterable<self>  $windowsInDisbursementOrder
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    public static function firstOpenWindow(
        iterable $windowsInDisbursementOrder,
        Carbon $paymentDate,
        array $cumulativeRepaidByLoanKey,
    ): ?self {
        foreach ($windowsInDisbursementOrder as $window) {
            if (!$window->isDisbursedOnOrBefore($paymentDate)) {
                continue;
            }

            $cumulative = $cumulativeRepaidByLoanKey[$window->loanKey] ?? 0.0;

            if ($window->hasRemainingRepayment($cumulative)) {
                return $window;
            }
        }

        return null;
    }
}
