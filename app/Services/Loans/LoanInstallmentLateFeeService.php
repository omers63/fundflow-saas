<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\LoanInstallment;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Support\BusinessDay;
use App\Support\ContributionCollectionStatus;
use App\Support\ContributionPolicySettings;
use App\Support\InstallmentCollectionStatus;
use App\Support\LegacyImportedLoan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Nightly tiered late fees for overdue EMIs (parity with contribution collection engine).
 */
class LoanInstallmentLateFeeService
{
    public function __construct(
        protected LateFeeService $lateFees,
        protected ContributionCycleService $cycles,
        protected AccountingService $accounting,
    ) {}

    public function applyNightlyLateFees(): int
    {
        $updated = 0;

        LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereNotNull('overdue_since')
            ->whereHas('loan', fn($q) => $q->whereIn('status', ['active', 'transferred']))
            ->with('loan.member')
            ->each(function (LoanInstallment $installment) use (&$updated): void {
                if ($this->applyLateFeeTierForInstallment($installment)) {
                    $updated++;
                }
            });

        return $updated;
    }

    public function applyLateFeeTierForInstallment(LoanInstallment $installment): bool
    {
        if ($installment->overdue_since === null) {
            return false;
        }

        $installment->loadMissing('loan');

        if ($installment->loan !== null && LegacyImportedLoan::isLoan($installment->loan)) {
            return false;
        }

        $days = $this->lateFees->daysPastDue(
            Carbon::parse($installment->overdue_since),
            BusinessDay::now(),
        );

        $newTier = ContributionCollectionStatus::tierForDays($days);
        $currentTier = (int) ($installment->late_fee_tier ?? 0);

        if ($newTier === null || $newTier === $currentTier) {
            return false;
        }

        $newFee = $this->lateFees->repaymentLateFeeForTier($newTier);
        $oldFee = (float) ($installment->late_fee_amount ?? 0);
        $feeToPost = ContributionPolicySettings::lateFeeModel() === 'cumulative'
            ? max(0.0, $newFee - $oldFee)
            : $newFee;

        DB::transaction(function () use ($installment, $newTier, $newFee, $oldFee, $feeToPost, $days): void {
            if (ContributionPolicySettings::lateFeeModel() === 'replacement' && $oldFee > 0.00001) {
                $this->accounting->reverseInstallmentLateFee($installment, $oldFee);
            }

            if ($feeToPost > 0.00001) {
                $this->accounting->postInstallmentLateFee($installment, $feeToPost);
            }

            $installment->update([
                'late_fee_tier' => $newTier,
                'late_fee_amount' => $newFee > 0.00001 ? $newFee : 0,
                'collection_status' => ContributionCollectionStatus::labelForTier($newTier)
                    ?? InstallmentCollectionStatus::OVERDUE,
                'is_late' => $days > ContributionPolicySettings::lateFeeReminderDays(),
            ]);
        });

        return true;
    }
}
