<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cash applied from an accepted deposit (contributions, loan installments, etc.).
 */
final readonly class FundPostingSettlementSummary
{
    public function __construct(
        public float $depositAmount,
        public float $contributionsApplied,
        public float $loanInstallmentsApplied,
        public float $remainingCash,
    ) {}

    public function totalApplied(): float
    {
        return $this->contributionsApplied + $this->loanInstallmentsApplied;
    }

    public function hasSettlement(): bool
    {
        return $this->totalApplied() > 0.00001;
    }

    /**
     * @return array{
     *     deposit_amount: float,
     *     contributions_applied: float,
     *     loan_installments_applied: float,
     *     total_applied: float,
     *     remaining_cash: float
     * }
     */
    public function toArray(): array
    {
        return [
            'deposit_amount' => $this->depositAmount,
            'contributions_applied' => $this->contributionsApplied,
            'loan_installments_applied' => $this->loanInstallmentsApplied,
            'total_applied' => $this->totalApplied(),
            'remaining_cash' => $this->remainingCash,
        ];
    }
}
