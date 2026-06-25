<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
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
        return $this->qualifiesForMinimumInstallment(
            $this->minimumInstallmentAmount($loan),
            $member,
            $amount,
            $cumulativeRepaidOnLoan,
        );
    }

    public function qualifiesForCsvWindowRepayment(
        LegacyLoanRepaymentWindow $window,
        Member $member,
        float $amount,
        float $cumulativeRepaidOnLoan = 0.0,
    ): bool {
        $minimumInstallment = (float) (LoanTier::forAmount($window->amountApproved)?->min_monthly_installment ?? 0);

        return $this->qualifiesForMinimumInstallment(
            $minimumInstallment,
            $member,
            $amount,
            $cumulativeRepaidOnLoan,
        );
    }

    private function qualifiesForMinimumInstallment(
        float $minimumInstallment,
        Member $member,
        float $amount,
        float $cumulativeRepaidOnLoan,
    ): bool {
        if ($amount <= self::AMOUNT_TOLERANCE) {
            return false;
        }

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
     * @return array{loan: ?Loan, repayment_amount: float, contribution_amount: float, window: ?LegacyLoanRepaymentWindow}
     */
    public function allocate(
        Member $member,
        float $amount,
        CarbonInterface $paidAt,
        array &$cumulativeRepaidByLoanKey,
        ?LegacyMigrationCsvLoanIndex $loanIndex = null,
        ?LegacyLoanRepaymentInstallmentTracker $installmentTracker = null,
    ): array {
        $amount = round($amount, 2);

        if ($amount <= self::AMOUNT_TOLERANCE) {
            return $this->contributionOnly($amount);
        }

        $window = $this->repaymentWindowResolver->resolveWindow(
            LegacyPaymentClassifyMember::fromDatabase($member),
            Carbon::parse($paidAt),
            $cumulativeRepaidByLoanKey,
            $loanIndex,
            $installmentTracker,
        );

        if ($window === null) {
            return $this->contributionOnly($amount);
        }

        $installmentTracker?->registerWindow($window);

        if ($installmentTracker?->isScheduleSatisfied($window->loanKey) === true) {
            return $this->contributionOnly($amount);
        }

        $useCsvLoans = $loanIndex !== null
            && ! $loanIndex->isEmpty()
            && $loanIndex->hasMember((string) $member->member_number);

        $loan = $window->loanId !== null
            ? Loan::query()->with('loanTier')->find($window->loanId)
            : null;

        if ($loan === null && ! $useCsvLoans) {
            return $this->contributionOnly($amount);
        }

        $cumulative = $cumulativeRepaidByLoanKey[$window->loanKey] ?? 0.0;

        if ($loan !== null) {
            if (! $this->qualifiesForLoanRepayment($loan, $member, $amount, $cumulative)) {
                return $this->contributionOnly($amount);
            }

            $targetRemaining = $window->remainingRepayment($cumulative);

            if ($targetRemaining <= self::AMOUNT_TOLERANCE) {
                return $this->contributionOnly($amount);
            }

            $scheduleCapacity = $installmentTracker?->remainingScheduleCapacity($window->loanKey)
                ?? $this->installmentSpilloverCapacity($loan, $amount);

            $maxLoanAllocation = min(
                $amount,
                $targetRemaining,
                $scheduleCapacity,
            );

            $repaymentAmount = round(max(0.0, $maxLoanAllocation), 2);

            if ($repaymentAmount <= self::AMOUNT_TOLERANCE) {
                return $this->contributionOnly($amount);
            }

            $installmentTracker?->applyRepayment($window->loanKey, $repaymentAmount);

            return [
                'loan' => $loan,
                'repayment_amount' => $repaymentAmount,
                'contribution_amount' => round(max(0.0, $amount - $repaymentAmount), 2),
                'window' => $window,
            ];
        }

        if (! $this->qualifiesForCsvWindowRepayment($window, $member, $amount, $cumulative)) {
            return $this->contributionOnly($amount);
        }

        $targetRemaining = $window->remainingRepayment($cumulative);

        if ($targetRemaining <= self::AMOUNT_TOLERANCE) {
            return $this->contributionOnly($amount);
        }

        $scheduleCapacity = $installmentTracker?->remainingScheduleCapacity($window->loanKey) ?? $amount;

        $repaymentAmount = round(min($amount, $targetRemaining, $scheduleCapacity), 2);

        if ($repaymentAmount <= self::AMOUNT_TOLERANCE) {
            return $this->contributionOnly($amount);
        }

        $installmentTracker?->applyRepayment($window->loanKey, $repaymentAmount);

        return [
            'loan' => null,
            'repayment_amount' => $repaymentAmount,
            'contribution_amount' => round(max(0.0, $amount - $repaymentAmount), 2),
            'window' => $window,
        ];
    }

    public function minimumInstallmentAmount(Loan $loan): float
    {
        return (float) ($loan->loanTier?->min_monthly_installment ?? 0);
    }

    /**
     * @return array{loan: null, repayment_amount: float, contribution_amount: float, window: null}
     */
    private function contributionOnly(float $amount): array
    {
        return [
            'loan' => null,
            'repayment_amount' => 0.0,
            'contribution_amount' => round($amount, 2),
            'window' => null,
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
