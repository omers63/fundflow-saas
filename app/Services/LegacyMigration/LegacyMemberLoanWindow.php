<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use Carbon\Carbon;

/**
 * One loan in a member's legacy repayment timeline (oldest disbursement first).
 */
final readonly class LegacyMemberLoanWindow
{
    public function __construct(
        public string $loanKey,
        public Carbon $disbursedAt,
        public float $fundPortionTarget,
        public ?int $loanId,
    ) {}

    public static function fromLoan(Loan $loan, string $memberNumber): self
    {
        $disbursedAt = $loan->disbursed_at?->copy()->startOfDay() ?? now()->startOfDay();

        return new self(
            loanKey: LegacyLoanRepaymentWindow::loanKey($memberNumber, $disbursedAt, (int) $loan->id),
            disbursedAt: $disbursedAt,
            fundPortionTarget: LegacyLoanRepaymentTarget::forLoan($loan),
            loanId: (int) $loan->id,
        );
    }

    /**
     * @param  array{
     *     disbursed_at: Carbon,
     *     amount_approved: float,
     *     legacy_loan_id: int|null,
     *     master_portion: float|null,
     *     settlement_threshold: float|null,
     * }  $csvLoan
     */
    public static function fromCsvLoan(string $memberNumber, array $csvLoan): self
    {
        $legacyLoanId = $csvLoan['legacy_loan_id'] ?? null;

        return new self(
            loanKey: LegacyLoanRepaymentWindow::loanKey($memberNumber, $csvLoan['disbursed_at'], $legacyLoanId),
            disbursedAt: $csvLoan['disbursed_at']->copy()->startOfDay(),
            fundPortionTarget: LegacyLoanRepaymentTarget::fromLoansCsvRow([
                'master_portion' => $csvLoan['master_portion'] !== null ? (string) $csvLoan['master_portion'] : '',
                'settlement_threshold' => $csvLoan['settlement_threshold'] !== null ? (string) $csvLoan['settlement_threshold'] : '',
            ], $csvLoan['amount_approved']),
            loanId: $legacyLoanId,
        );
    }

    public function acceptsRepaymentOn(Carbon $paymentDate): bool
    {
        return $paymentDate->copy()->startOfDay()->gte($this->disbursedAt);
    }

    public function remainingFundPortion(float $cumulativeRepaid): float
    {
        return LegacyLoanRepaymentTarget::remainingFundPortionObligation(
            $this->fundPortionTarget,
            $cumulativeRepaid,
        );
    }

    public function isFundPortionSatisfied(float $cumulativeRepaid): bool
    {
        return ! LegacyLoanRepaymentTarget::hasRemainingFundPortion(
            $this->fundPortionTarget,
            $cumulativeRepaid,
        );
    }
}
