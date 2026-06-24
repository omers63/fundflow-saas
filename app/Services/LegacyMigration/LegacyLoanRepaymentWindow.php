<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
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
        public Carbon $firstRepaymentAt,
        public ?int $loanId = null,
    ) {}

    public static function firstRepaymentAtForDisbursement(Carbon $disbursedAt, int $graceCycles): Carbon
    {
        $exemption = Loan::computeExemptionAndFirstRepayment($disbursedAt, max(0, min(2, $graceCycles)));

        return Carbon::create(
            $exemption['first_repayment_year'],
            $exemption['first_repayment_month'],
            1,
        )->startOfDay();
    }

    public static function firstRepaymentAtForLoan(Loan $loan, int $defaultGraceCycles): Carbon
    {
        $disbursedAt = $loan->disbursed_at?->copy()->startOfDay() ?? now()->startOfDay();

        if ($loan->first_repayment_year !== null && $loan->first_repayment_month !== null) {
            return Carbon::create(
                (int) $loan->first_repayment_year,
                (int) $loan->first_repayment_month,
                1,
            )->startOfDay();
        }

        $graceCycles = $loan->grace_cycles ?? ($loan->has_grace_cycle ? 1 : $defaultGraceCycles);

        return self::firstRepaymentAtForDisbursement($disbursedAt, (int) $graceCycles);
    }

    public static function loanKey(string $memberNumber, Carbon $disbursedAt): string
    {
        return trim($memberNumber).'|'.$disbursedAt->toDateString();
    }

    public function acceptsRepaymentOn(Carbon $paymentDate): bool
    {
        return $paymentDate->gte($this->firstRepaymentAt);
    }

    public function hasRemainingRepayment(float $cumulativeRepaid): bool
    {
        return $cumulativeRepaid + 0.00001 < $this->repaymentTargetAmount;
    }

    public function isRepaymentWindowClosed(float $cumulativeRepaid): bool
    {
        return ! $this->hasRemainingRepayment($cumulativeRepaid);
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
            if (! $window->acceptsRepaymentOn($paymentDate)) {
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
