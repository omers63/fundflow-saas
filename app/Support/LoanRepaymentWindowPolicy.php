<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use App\Services\ContributionCycleService;
use Carbon\Carbon;

/**
 * Central rules for when loan repayment windows and contribution cycles open/close.
 *
 * - Legacy migration: repayments may be classified from disbursement onward.
 * - Normal loans: the repayment window opens on the first day of the first repayment
 *   cycle after grace (contribution cycle start day, not calendar day 1).
 * - Collection cycles: open on {@see Setting::contributionCycleStartDay()} and close
 *   the day before the next cycle starts (see {@see ContributionCycleService}).
 */
final class LoanRepaymentWindowPolicy
{
    public function __construct(
        private readonly ContributionCycleService $cycles,
    ) {}

    public function legacyMigrationWindowOpensAt(Carbon $disbursedAt): Carbon
    {
        return $disbursedAt->copy()->startOfDay();
    }

    public function normalLoanWindowOpensAt(int $firstRepaymentMonth, int $firstRepaymentYear): Carbon
    {
        return $this->cycles->cycleStartAt($firstRepaymentMonth, $firstRepaymentYear);
    }

    public function normalLoanWindowOpensAtForLoan(Loan $loan): Carbon
    {
        if ($loan->first_repayment_year !== null && $loan->first_repayment_month !== null) {
            return $this->normalLoanWindowOpensAt(
                (int) $loan->first_repayment_month,
                (int) $loan->first_repayment_year,
            );
        }

        $disbursedAt = $loan->disbursed_at?->copy()->startOfDay() ?? BusinessDay::today();
        $graceCycles = (int) ($loan->grace_cycles ?? ($loan->has_grace_cycle ? 1 : 0));

        return $this->firstRepaymentCycleStartForDisbursement($disbursedAt, $graceCycles);
    }

    public function firstRepaymentCycleStartForDisbursement(Carbon $disbursedAt, int $graceCycles): Carbon
    {
        $exemption = Loan::computeExemptionAndFirstRepayment($disbursedAt, LoanSettings::clampGraceCycles($graceCycles));

        return $this->normalLoanWindowOpensAt(
            $exemption['first_repayment_month'],
            $exemption['first_repayment_year'],
        );
    }

    public function installmentDueDateForCycle(int $month, int $year): Carbon
    {
        return $this->cycles->cycleDueEndAt($month, $year)->copy()->startOfDay();
    }

    public function acceptsRepaymentOn(Carbon $paymentDate, Carbon $windowOpensAt): bool
    {
        return $paymentDate->copy()->startOfDay()->gte($windowOpensAt->copy()->startOfDay());
    }
}
