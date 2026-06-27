<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

/**
 * Result of classifying one legacy payment against a member's loan timeline.
 */
final readonly class LegacyPaymentAllocation
{
    public function __construct(
        public float $repaymentAmount,
        public float $contributionAmount,
        public ?int $loanId,
        public ?string $loanKey,
    ) {}

    public function isContributionOnly(): bool
    {
        return $this->repaymentAmount <= LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE;
    }

    public function isRepaymentOnly(): bool
    {
        return $this->contributionAmount <= LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE
            && $this->repaymentAmount > LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE;
    }

    public function isSplit(): bool
    {
        return $this->repaymentAmount > LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE
            && $this->contributionAmount > LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE;
    }

    public function primaryType(): string
    {
        return $this->repaymentAmount > LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE
            ? 'loan_repayment'
            : 'contribution';
    }
}
