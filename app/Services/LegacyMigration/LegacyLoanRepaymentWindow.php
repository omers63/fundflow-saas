<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Support\BusinessDay;
use App\Support\LoanRepaymentWindowPolicy;
use Carbon\Carbon;

/**
 * Loan repayment window used by the legacy payment classifier.
 *
 * Legacy migration always opens the repayment window at disbursement. Grace cycles
 * still determine the first EMI cycle on imported loans, not when repayments may
 * be classified.
 */
final readonly class LegacyLoanRepaymentWindow
{
    public function __construct(
        public string $loanKey,
        public Carbon $disbursedAt,
        public float $amountApproved,
        public float $repaymentTargetAmount,
        public Carbon $firstRepaymentAt,
        public ?int $loanId = null,
        public int $graceCycles = 0,
        public ?string $memberNumber = null,
        public ?int $installmentsCount = null,
    ) {}

    public static function firstRepaymentAtForDisbursement(Carbon $disbursedAt, int $graceCycles): Carbon
    {
        return app(LoanRepaymentWindowPolicy::class)
            ->firstRepaymentCycleStartForDisbursement($disbursedAt, $graceCycles);
    }

    public static function firstRepaymentAtForLoan(Loan $loan, int $defaultGraceCycles): Carbon
    {
        if ($loan->first_repayment_year !== null && $loan->first_repayment_month !== null) {
            return app(LoanRepaymentWindowPolicy::class)->normalLoanWindowOpensAt(
                (int) $loan->first_repayment_month,
                (int) $loan->first_repayment_year,
            );
        }

        $disbursedAt = $loan->disbursed_at?->copy()->startOfDay() ?? BusinessDay::today();
        $graceCycles = $loan->grace_cycles ?? ($loan->has_grace_cycle ? 1 : $defaultGraceCycles);

        return self::firstRepaymentAtForDisbursement($disbursedAt, (int) $graceCycles);
    }

    public static function loanKey(string $memberNumber, Carbon $disbursedAt, ?int $loanId = null): string
    {
        if ($loanId !== null && $loanId > 0) {
            return (string) $loanId;
        }

        return trim($memberNumber).'|'.$disbursedAt->toDateString();
    }

    public function repaymentWindowOpensAt(): Carbon
    {
        return app(LoanRepaymentWindowPolicy::class)->legacyMigrationWindowOpensAt($this->disbursedAt);
    }

    public function acceptsRepaymentOn(Carbon $paymentDate): bool
    {
        return app(LoanRepaymentWindowPolicy::class)->acceptsRepaymentOn(
            $paymentDate,
            $this->repaymentWindowOpensAt(),
        );
    }

    public function hasRemainingRepayment(float $cumulativeRepaid): bool
    {
        return LegacyLoanRepaymentTarget::hasRemainingFundPortion(
            $this->repaymentTargetAmount,
            $cumulativeRepaid,
        );
    }

    public function isRepaymentWindowClosed(float $cumulativeRepaid): bool
    {
        return ! $this->hasRemainingRepayment($cumulativeRepaid);
    }

    public function remainingRepayment(float $cumulativeRepaid): float
    {
        return LegacyLoanRepaymentTarget::remainingFundPortionObligation(
            $this->repaymentTargetAmount,
            $cumulativeRepaid,
        );
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
        ?LegacyLoanRepaymentInstallmentTracker $installmentTracker = null,
    ): ?self {
        foreach ($windowsInDisbursementOrder as $window) {
            if (! $window->acceptsRepaymentOn($paymentDate)) {
                continue;
            }

            if ($installmentTracker !== null) {
                $installmentTracker->registerWindow($window);

                $cumulative = $cumulativeRepaidByLoanKey[$window->loanKey] ?? 0.0;

                if (
                    $installmentTracker->isScheduleSatisfied($window->loanKey)
                    && $window->isRepaymentWindowClosed($cumulative)
                ) {
                    continue;
                }
            }

            $cumulative = $cumulativeRepaidByLoanKey[$window->loanKey] ?? 0.0;

            if ($window->hasRemainingRepayment($cumulative)) {
                return $window;
            }
        }

        return null;
    }
}
