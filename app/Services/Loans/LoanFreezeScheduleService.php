<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Services\FundAuditLogService;
use App\Support\InstallmentCollectionStatus;
use App\Support\LoanRepaymentWindowPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Push / pull unpaid EMI schedules for membership freeze (one contribution cycle at a time).
 */
final class LoanFreezeScheduleService
{
    public function __construct(
        private ContributionCycleService $cycles,
        private LoanRepaymentWindowPolicy $repaymentWindow,
        private FundAuditLogService $audit,
    ) {}

    /**
     * Push unpaid installments forward by one labelled contribution cycle for each active loan.
     *
     * @return int Number of loans shifted
     */
    public function pushOneCycleForMember(Member $member): int
    {
        $loans = Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', ['active', 'transferred', 'partially_disbursed'])
            ->with(['installments' => fn ($q) => $q->whereIn('status', ['pending', 'overdue'])->orderBy('installment_number')])
            ->get();

        $shifted = 0;

        foreach ($loans as $loan) {
            if ($loan->installments->isEmpty()) {
                continue;
            }

            $this->shiftLoan($loan, 1);
            $shifted++;
        }

        return $shifted;
    }

    /**
     * Reverse freeze pushes (early unfreeze). Paid installments are left alone.
     *
     * @return int Number of loans shifted
     */
    public function pullCyclesForMember(Member $member, int $cycles): int
    {
        if ($cycles < 1) {
            return 0;
        }

        $loans = Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', ['active', 'transferred', 'partially_disbursed'])
            ->with(['installments' => fn ($q) => $q->whereIn('status', ['pending', 'overdue'])->orderBy('installment_number')])
            ->get();

        $shifted = 0;

        foreach ($loans as $loan) {
            if ($loan->installments->isEmpty()) {
                continue;
            }

            $this->shiftLoan($loan, -$cycles);
            $shifted++;
        }

        return $shifted;
    }

    private function shiftLoan(Loan $loan, int $cycles): void
    {
        DB::transaction(function () use ($loan, $cycles): void {
            foreach ($loan->installments as $installment) {
                $this->shiftInstallmentCycles($installment, $cycles);
            }

            $this->applyLoanMetadata($loan, $cycles);

            $this->audit->log(
                $cycles > 0 ? 'LOAN_FREEZE_SCHEDULE_PUSH' : 'LOAN_FREEZE_SCHEDULE_PULL',
                'loan',
                $loan->fresh(),
                $loan->member,
                [
                    'cycles_shifted' => $cycles,
                    'installments_shifted' => $loan->installments->count(),
                ],
            );
        });
    }

    private function applyLoanMetadata(Loan $loan, int $cycles): void
    {
        $updates = [];

        $firstMonth = $loan->first_repayment_month !== null ? (int) $loan->first_repayment_month : null;
        $firstYear = $loan->first_repayment_year !== null ? (int) $loan->first_repayment_year : null;

        if ($firstMonth !== null && $firstYear !== null) {
            $firstNext = Carbon::create($firstYear, $firstMonth, 1)->addMonthsNoOverflow($cycles);
            $updates['first_repayment_month'] = (int) $firstNext->month;
            $updates['first_repayment_year'] = (int) $firstNext->year;
        }

        if ($loan->exempted_month !== null && $loan->exempted_year !== null) {
            $exNext = Carbon::create((int) $loan->exempted_year, (int) $loan->exempted_month, 1)
                ->addMonthsNoOverflow($cycles);
            $updates['exempted_month'] = (int) $exNext->month;
            $updates['exempted_year'] = (int) $exNext->year;
        }

        if ($loan->due_date !== null) {
            $updates['due_date'] = Carbon::parse($loan->due_date)->addMonthsNoOverflow($cycles)->toDateString();
        }

        // Freeze push should not inflate permanent grace_cycles the way manual remediation does.
        if ($updates !== []) {
            $loan->update($updates);
        }
    }

    private function shiftInstallmentCycles(LoanInstallment $installment, int $cycles): void
    {
        if ($installment->due_date === null || $cycles === 0) {
            return;
        }

        [$cycleMonth, $cycleYear] = $this->cycles->cyclePeriodForDueDate($installment->due_date);
        $period = Carbon::create($cycleYear, $cycleMonth, 1)->addMonthsNoOverflow($cycles);
        $newDue = $this->repaymentWindow->installmentDueDateForCycle(
            (int) $period->month,
            (int) $period->year,
        );

        $attributes = [
            'due_date' => $newDue->toDateString(),
        ];

        if ($cycles > 0 && $installment->status === 'overdue') {
            $attributes['status'] = 'pending';
            $attributes['collection_status'] = InstallmentCollectionStatus::PENDING;
            $attributes['overdue_since'] = null;
            $attributes['is_late'] = false;
            $attributes['late_fee_amount'] = 0;
            $attributes['late_fee_tier'] = 0;
        }

        $installment->update($attributes);
    }
}
