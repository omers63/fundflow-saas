<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use Carbon\Carbon;

/**
 * Legacy payment classification per member:
 *
 * 1. Payments are read from the upload file and sorted by date.
 * 2. Loans are ordered oldest disbursement → newest; each window opens on disbursement.
 * 3. Before a loan window: every payment is a contribution for the payment-date cycle.
 * 4. Inside an open window with fund portion remaining: payments are loan repayments.
 * 5. Repayments continue until the fund portion target is satisfied.
 * 6. When the fund portion is satisfied mid-payment, the remainder is a contribution
 *    for the same payment-date cycle; later payments are contributions again.
 */
final class LegacyMemberPaymentChronology
{
    /**
     * @param  list<LegacyMemberLoanWindow>  $loansOldestFirst
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    public function allocate(
        Carbon $paymentDate,
        float $amount,
        array $loansOldestFirst,
        array &$cumulativeRepaidByLoanKey,
        bool $commit = true,
    ): LegacyPaymentAllocation {
        $amount = round($amount, 2);

        if ($amount <= LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
            return new LegacyPaymentAllocation(0.0, $amount, null, null);
        }

        foreach ($loansOldestFirst as $loan) {
            if ($loan->fundPortionTarget <= LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
                continue;
            }

            if (! $loan->acceptsRepaymentOn($paymentDate)) {
                continue;
            }

            $cumulative = $cumulativeRepaidByLoanKey[$loan->loanKey] ?? 0.0;

            if ($loan->isFundPortionSatisfied($cumulative)) {
                continue;
            }

            $fundRemaining = $loan->remainingFundPortion($cumulative);
            $repaymentSlice = round(min($amount, $fundRemaining), 2);

            if ($repaymentSlice <= LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
                break;
            }

            if ($commit) {
                $cumulativeRepaidByLoanKey[$loan->loanKey] = round($cumulative + $repaymentSlice, 2);
            }

            return new LegacyPaymentAllocation(
                repaymentAmount: $repaymentSlice,
                contributionAmount: round(max(0.0, $amount - $repaymentSlice), 2),
                loanId: $loan->loanId,
                loanKey: $loan->loanKey,
            );
        }

        return new LegacyPaymentAllocation(
            repaymentAmount: 0.0,
            contributionAmount: $amount,
            loanId: null,
            loanKey: null,
        );
    }

    /**
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    public function recordRepayment(string $loanKey, float $amount, array &$cumulativeRepaidByLoanKey): void
    {
        if ($amount <= LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
            return;
        }

        $cumulativeRepaidByLoanKey[$loanKey] = round(
            ($cumulativeRepaidByLoanKey[$loanKey] ?? 0.0) + $amount,
            2,
        );
    }
}
