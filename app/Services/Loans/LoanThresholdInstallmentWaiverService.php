<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Support\BusinessDay;
use App\Support\ContributionPolicySettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Waive remaining settlement-threshold EMIs after the master fund slice is fully repaid.
 */
final class LoanThresholdInstallmentWaiverService
{
    public function canWaive(Loan $loan): bool
    {
        return $this->ineligibilityReason($loan) === null;
    }

    public function ineligibilityReason(Loan $loan): ?string
    {
        if ($loan->status !== 'active') {
            return __('Only active loans can have threshold installments waived.');
        }

        if (! $loan->installments()->whereIn('status', ['pending', 'overdue'])->exists()) {
            return __('This loan has no unpaid installments to waive.');
        }

        $tolerance = ContributionPolicySettings::reconTolerance();
        $masterPortion = (float) $loan->master_portion;

        if ($masterPortion > $tolerance && (float) $loan->repaid_to_master < $masterPortion - $tolerance) {
            return __('The master fund portion must be fully repaid before threshold installments can be waived.');
        }

        $threshold = $loan->fullRepaymentThreshold();
        $collected = $loan->totalPrincipalCollected();

        if ($collected >= $threshold - $tolerance) {
            return __('Repayment threshold is already met through collected installments.');
        }

        $remainingScheduled = $loan->getScheduledOutstanding();
        $settlementRemainder = round($threshold - max($collected, $masterPortion), 2);

        if ($remainingScheduled > $settlementRemainder + $tolerance) {
            return __('Outstanding installments exceed the remaining settlement-threshold portion. Collect or adjust the schedule first.');
        }

        return null;
    }

    /**
     * @return Collection<int, LoanInstallment>
     */
    public function waivableInstallments(Loan $loan): Collection
    {
        return $loan->installments()
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('installment_number')
            ->get();
    }

    public function waiveRemaining(Loan $loan, string $reason, ?int $waivedById = null): Loan
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException(__('A waiver reason is required for exceptional threshold forgiveness.'));
        }

        $ineligible = $this->ineligibilityReason($loan);

        if ($ineligible !== null) {
            throw new InvalidArgumentException($ineligible);
        }

        $at = BusinessDay::now();

        DB::transaction(function () use ($loan, $reason, $waivedById, $at): void {
            $loan->installments()
                ->whereIn('status', ['pending', 'overdue'])
                ->each(function (LoanInstallment $installment) use ($at): void {
                    $installment->update([
                        'status' => 'waived',
                        'waived_at' => $at,
                        'amount_collected' => 0,
                        'is_late' => false,
                        'late_fee_amount' => 0,
                    ]);
                });

            $loan->update([
                'status' => 'completed',
                'settled_at' => $at,
                'threshold_waived_at' => $at,
                'threshold_waiver_reason' => $reason,
                'threshold_waived_by_id' => $waivedById,
            ]);

            $loan->refresh();
            $loan->releaseGuarantorIfDue();
        });

        if ($loan->fund_tier_id !== null) {
            LoanQueueOrderingService::resequenceFundTier((int) $loan->fund_tier_id);
        }

        return $loan->fresh(['installments', 'member']);
    }
}
