<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Splits legacy payment amounts between loan repayments (within repayment windows)
 * and contributions. Payments below the loan tier EMI are contributions only at
 * the beginning of a loan repayment cycle (before any repayment has been applied).
 */
final class LegacyPaymentLoanAllocator
{
    private const float AMOUNT_TOLERANCE = 0.02;

    public function __construct(
        private readonly LegacyLoanRepaymentWindowResolver $repaymentWindowResolver,
    ) {}

    public function isAtRepaymentCycleStart(float $cumulativeRepaidOnLoan): bool
    {
        return $cumulativeRepaidOnLoan <= self::AMOUNT_TOLERANCE;
    }

    public function qualifiesForLoanRepayment(Loan $loan, Member $member, float $amount, float $cumulativeRepaidOnLoan = 0.0): bool
    {
        if ($amount <= self::AMOUNT_TOLERANCE) {
            return false;
        }

        $minimumInstallment = $this->minimumInstallmentAmount($loan);

        if ($this->isAtRepaymentCycleStart($cumulativeRepaidOnLoan)) {
            $monthlyContribution = round((float) $member->monthly_contribution_amount, 2);

            if (
                $monthlyContribution > self::AMOUNT_TOLERANCE
                && abs($amount - $monthlyContribution) <= self::AMOUNT_TOLERANCE
            ) {
                return true;
            }

            if (
                $minimumInstallment > self::AMOUNT_TOLERANCE
                && $amount + self::AMOUNT_TOLERANCE < $minimumInstallment
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     * @return array{loan: ?Loan, repayment_amount: float, contribution_amount: float}
     */
    public function allocate(
        Member $member,
        float $amount,
        CarbonInterface $paidAt,
        array &$cumulativeRepaidByLoanKey,
    ): array {
        $amount = round($amount, 2);

        if ($amount <= self::AMOUNT_TOLERANCE) {
            return $this->contributionOnly($amount);
        }

        $window = $this->repaymentWindowResolver->resolveWindow(
            LegacyPaymentClassifyMember::fromDatabase($member),
            Carbon::parse($paidAt),
            $cumulativeRepaidByLoanKey,
        );

        if ($window === null || $window->loanId === null) {
            return $this->contributionOnly($amount);
        }

        $loan = Loan::query()->with('loanTier')->find($window->loanId);

        if ($loan === null) {
            return $this->contributionOnly($amount);
        }

        $cumulative = $cumulativeRepaidByLoanKey[$window->loanKey] ?? 0.0;

        if (! $this->qualifiesForLoanRepayment($loan, $member, $amount, $cumulative)) {
            return $this->contributionOnly($amount);
        }
        $targetRemaining = $window->remainingRepayment($cumulative);

        if ($targetRemaining <= self::AMOUNT_TOLERANCE) {
            return $this->contributionOnly($amount);
        }

        $maxLoanAllocation = min(
            $amount,
            $targetRemaining,
            $this->installmentSpilloverCapacity($loan, $amount),
        );

        $repaymentAmount = round(max(0.0, $maxLoanAllocation), 2);

        if ($repaymentAmount <= self::AMOUNT_TOLERANCE) {
            return $this->contributionOnly($amount);
        }

        return [
            'loan' => $loan,
            'repayment_amount' => $repaymentAmount,
            'contribution_amount' => round(max(0.0, $amount - $repaymentAmount), 2),
        ];
    }

    public function minimumInstallmentAmount(Loan $loan): float
    {
        return (float) ($loan->loanTier?->min_monthly_installment ?? 0);
    }

    /**
     * @return array{loan: null, repayment_amount: float, contribution_amount: float}
     */
    private function contributionOnly(float $amount): array
    {
        return [
            'loan' => null,
            'repayment_amount' => 0.0,
            'contribution_amount' => round($amount, 2),
        ];
    }

    /**
     * Maximum loan allocation for a payment, allowing spillover across multiple
     * pending installments before any remainder is routed to contributions.
     */
    public function installmentSpilloverCapacity(Loan $loan, float $amount): float
    {
        $scheduleCapacity = $this->remainingScheduleCapacity($loan);

        if ($scheduleCapacity <= self::AMOUNT_TOLERANCE) {
            return round($amount, 2);
        }

        return round(min($amount, $scheduleCapacity), 2);
    }

    public function remainingScheduleCapacity(Loan $loan): float
    {
        $pendingSum = (float) $loan->installments()
            ->whereIn('status', ['pending', 'overdue'])
            ->sum('amount');

        $minimumInstallment = $this->minimumInstallmentAmount($loan);
        $installmentCount = max(0, (int) $loan->installments_count);
        $paidCount = (int) $loan->installments()->where('status', 'paid')->count();
        $remainingInstallmentCount = max(0, $installmentCount - $paidCount);

        if ($minimumInstallment > self::AMOUNT_TOLERANCE && $remainingInstallmentCount > 0) {
            $fromCount = round($remainingInstallmentCount * $minimumInstallment, 2);

            return round(max($pendingSum, $fromCount), 2);
        }

        return round($pendingSum, 2);
    }
}
