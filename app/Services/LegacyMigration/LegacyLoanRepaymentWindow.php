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

    public function remainingRepayment(float $cumulativeRepaid): float
    {
        return max(0.0, round($this->repaymentTargetAmount - $cumulativeRepaid, 2));
    }
}
