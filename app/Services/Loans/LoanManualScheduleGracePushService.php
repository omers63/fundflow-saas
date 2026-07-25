<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Services\ContributionCycleService;
use App\Services\FundAuditLogService;
use App\Support\InstallmentCollectionStatus;
use App\Support\LoanRepaymentWindowPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Manual remediation for one loan: push unpaid EMIs forward N cycles and extend
 * beginning grace so the previous first-repayment cycle(s) become grace-exempt.
 *
 * Example: first repayment Jan 2025, push 1 → first repayment Feb 2025, Jan marked grace-exempt.
 */
final class LoanManualScheduleGracePushService
{
    public const MIN_CYCLES = 1;

    public const MAX_CYCLES = 6;

    public function __construct(
        private ContributionCycleService $cycles,
        private LoanRepaymentWindowPolicy $repaymentWindow,
        private FundAuditLogService $audit,
    ) {}

    public static function clampCycles(int $cycles): int
    {
        return max(self::MIN_CYCLES, min(self::MAX_CYCLES, $cycles));
    }

    /**
     * @return array{
     *     shifted: bool,
     *     installments: int,
     *     dry_run: bool,
     *     cycles: int,
     *     loan_id: int,
     *     previous_grace_cycles: int,
     *     new_grace_cycles: int,
     *     previous_first_repayment: string|null,
     *     new_first_repayment: string|null,
     *     message: string|null
     * }
     */
    public function push(int $loanId, int $cycles = 1, bool $dryRun = false): array
    {
        $cycles = self::clampCycles($cycles);

        $loan = Loan::query()
            ->with(['installments' => fn ($q) => $q->orderBy('installment_number')])
            ->find($loanId);

        if (! $loan instanceof Loan) {
            throw new InvalidArgumentException(__('Loan #:id was not found.', ['id' => $loanId]));
        }

        if (! in_array($loan->status, ['active', 'transferred'], true)) {
            throw new InvalidArgumentException(__('Loan #:id must be active or transferred to push its schedule.', [
                'id' => $loanId,
            ]));
        }

        $installments = $loan->installments->values();

        if ($installments->isEmpty()) {
            throw new InvalidArgumentException(__('Loan #:id has no installments to shift.', [
                'id' => $loanId,
            ]));
        }

        $previousGrace = (int) ($loan->grace_cycles ?? 0);
        $newGrace = min(12, $previousGrace + $cycles);
        $previousFirst = $this->formatFirstRepayment($loan);

        if ($dryRun) {
            $projectedFirst = $this->projectFirstRepayment($loan, $cycles);

            return [
                'shifted' => true,
                'installments' => $installments->count(),
                'dry_run' => true,
                'cycles' => $cycles,
                'loan_id' => (int) $loan->id,
                'previous_grace_cycles' => $previousGrace,
                'new_grace_cycles' => $newGrace,
                'previous_first_repayment' => $previousFirst,
                'new_first_repayment' => $projectedFirst,
                'message' => null,
            ];
        }

        DB::transaction(function () use ($loan, $installments, $cycles, $previousGrace, $newGrace, $previousFirst): void {
            foreach ($installments as $installment) {
                $this->shiftInstallmentCycles($installment, $cycles);
            }

            $this->applyLoanGraceMetadata($loan, $cycles, $newGrace);

            $fresh = $loan->fresh();

            $this->audit->log('LOAN_MANUAL_SCHEDULE_GRACE_PUSH', 'loan', $fresh, $loan->member, [
                'installments_shifted' => $installments->count(),
                'cycles_shifted' => $cycles,
                'previous_grace_cycles' => $previousGrace,
                'new_grace_cycles' => (int) ($fresh?->grace_cycles ?? $newGrace),
                'previous_first_repayment' => $previousFirst,
                'new_first_repayment' => $this->formatFirstRepayment($fresh ?? $loan),
                'exempted' => $fresh !== null && $fresh->exempted_month !== null
                    ? sprintf('%d/%d', (int) $fresh->exempted_month, (int) $fresh->exempted_year)
                    : null,
            ]);
        });

        $fresh = $loan->fresh();

        return [
            'shifted' => true,
            'installments' => $installments->count(),
            'dry_run' => false,
            'cycles' => $cycles,
            'loan_id' => (int) $loan->id,
            'previous_grace_cycles' => $previousGrace,
            'new_grace_cycles' => (int) ($fresh?->grace_cycles ?? $newGrace),
            'previous_first_repayment' => $previousFirst,
            'new_first_repayment' => $this->formatFirstRepayment($fresh ?? $loan),
            'message' => null,
        ];
    }

    private function applyLoanGraceMetadata(Loan $loan, int $cycles, int $newGraceCycles): void
    {
        $updates = [
            'grace_cycles' => $newGraceCycles,
            'has_grace_cycle' => $newGraceCycles > 0,
        ];

        $firstMonth = $loan->first_repayment_month !== null ? (int) $loan->first_repayment_month : null;
        $firstYear = $loan->first_repayment_year !== null ? (int) $loan->first_repayment_year : null;

        if ($firstMonth === null || $firstYear === null) {
            $earliest = $loan->installments
                ->sortBy('due_date')
                ->first();

            if ($earliest instanceof LoanInstallment && $earliest->due_date !== null) {
                [$firstMonth, $firstYear] = $this->cycles->cyclePeriodForDueDate($earliest->due_date);
            }
        }

        if ($firstMonth !== null && $firstYear !== null) {
            if ($loan->exempted_month === null || $loan->exempted_year === null) {
                // Store the last grace month (matches ContributionExemptionPolicy::graceCycleLabels).
                $lastGrace = Carbon::create($firstYear, $firstMonth, 1)->addMonthsNoOverflow($cycles - 1);
                $updates['exempted_month'] = (int) $lastGrace->month;
                $updates['exempted_year'] = (int) $lastGrace->year;
            } else {
                $exNext = Carbon::create(
                    (int) $loan->exempted_year,
                    (int) $loan->exempted_month,
                    1,
                )->addMonthsNoOverflow($cycles);
                $updates['exempted_month'] = (int) $exNext->month;
                $updates['exempted_year'] = (int) $exNext->year;
            }

            $firstNext = Carbon::create($firstYear, $firstMonth, 1)->addMonthsNoOverflow($cycles);
            $updates['first_repayment_month'] = (int) $firstNext->month;
            $updates['first_repayment_year'] = (int) $firstNext->year;
        }

        if ($loan->due_date !== null) {
            $updates['due_date'] = Carbon::parse($loan->due_date)->addMonthsNoOverflow($cycles)->toDateString();
        }

        $loan->update($updates);
    }

    private function shiftInstallmentCycles(LoanInstallment $installment, int $cycles): void
    {
        if ($installment->due_date === null) {
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

        if ($installment->status === 'overdue') {
            $attributes['status'] = 'pending';
            $attributes['collection_status'] = InstallmentCollectionStatus::PENDING;
            $attributes['overdue_since'] = null;
            $attributes['is_late'] = false;
            $attributes['late_fee_amount'] = 0;
            $attributes['late_fee_tier'] = 0;
        }

        $installment->update($attributes);
    }

    private function projectFirstRepayment(Loan $loan, int $cycles): ?string
    {
        $month = $loan->first_repayment_month !== null ? (int) $loan->first_repayment_month : null;
        $year = $loan->first_repayment_year !== null ? (int) $loan->first_repayment_year : null;

        if ($month === null || $year === null) {
            return null;
        }

        $next = Carbon::create($year, $month, 1)->addMonthsNoOverflow($cycles);

        return sprintf('%d/%d', (int) $next->month, (int) $next->year);
    }

    private function formatFirstRepayment(?Loan $loan): ?string
    {
        if ($loan === null || $loan->first_repayment_month === null || $loan->first_repayment_year === null) {
            return null;
        }

        return sprintf('%d/%d', (int) $loan->first_repayment_month, (int) $loan->first_repayment_year);
    }
}
