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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One-time remediation: loans with no beginning grace that show exactly one overdue
 * cycle get their unpaid schedule pushed N cycles forward (grace inserted at the start).
 */
final class LoanSingleOverdueGraceShiftService
{
    public const MIN_GRACE_CYCLES = 1;

    public const MAX_GRACE_CYCLES = 6;

    public function __construct(
        private ContributionCycleService $cycles,
        private LoanRepaymentWindowPolicy $repaymentWindow,
        private FundAuditLogService $audit,
    ) {}

    public static function clampGraceCycles(int $graceCycles): int
    {
        return max(self::MIN_GRACE_CYCLES, min(self::MAX_GRACE_CYCLES, $graceCycles));
    }

    /**
     * @return array{shifted: int, installments: int, skipped: int, dry_run: bool, grace_cycles: int, loan_ids: list<int>}
     */
    public function shiftEligibleLoans(bool $dryRun = false, ?int $loanId = null, int $graceCycles = 1): array
    {
        $graceCycles = self::clampGraceCycles($graceCycles);
        $shifted = 0;
        $installments = 0;
        $skipped = 0;
        /** @var list<int> $loanIds */
        $loanIds = [];

        foreach ($this->eligibleLoans($loanId) as $loan) {
            $count = $this->shiftLoan($loan, $dryRun, $graceCycles);

            if ($count === 0) {
                $skipped++;

                continue;
            }

            $shifted++;
            $installments += $count;
            $loanIds[] = (int) $loan->id;
        }

        return [
            'shifted' => $shifted,
            'installments' => $installments,
            'skipped' => $skipped,
            'dry_run' => $dryRun,
            'grace_cycles' => $graceCycles,
            'loan_ids' => $loanIds,
        ];
    }

    /**
     * @return Collection<int, Loan>
     */
    public function eligibleLoans(?int $loanId = null): Collection
    {
        $query = Loan::query()
            ->whereIn('status', ['active', 'transferred'])
            ->where(function ($q): void {
                $q->whereNull('grace_cycles')
                    ->orWhere('grace_cycles', '<=', 0);
            })
            ->whereHas('installments', fn ($q) => $q->where('status', 'overdue'), '=', 1)
            ->with(['installments' => fn ($q) => $q->orderBy('installment_number')]);

        if ($loanId !== null) {
            $query->whereKey($loanId);
        }

        return $query->orderBy('id')->get();
    }

    public function shiftLoan(Loan $loan, bool $dryRun = false, int $graceCycles = 1): int
    {
        $graceCycles = self::clampGraceCycles($graceCycles);
        $loan->loadMissing('installments');

        $overdue = $loan->installments->where('status', 'overdue');

        if ($overdue->count() !== 1) {
            return 0;
        }

        if ((int) ($loan->grace_cycles ?? 0) > 0) {
            return 0;
        }

        $unpaid = $loan->installments
            ->whereIn('status', ['pending', 'overdue'])
            ->values();

        if ($unpaid->isEmpty()) {
            return 0;
        }

        if ($dryRun) {
            return $unpaid->count();
        }

        DB::transaction(function () use ($loan, $unpaid, $graceCycles): void {
            foreach ($unpaid as $installment) {
                $this->shiftInstallmentCycles($installment, $graceCycles);
            }

            $this->applyLoanGraceMetadata($loan, $graceCycles);

            $this->audit->log('LOAN_SINGLE_OVERDUE_GRACE_SHIFT', 'loan', $loan->fresh(), $loan->member, [
                'installments_shifted' => $unpaid->count(),
                'cycles_shifted' => $graceCycles,
                'previous_grace_cycles' => 0,
                'new_grace_cycles' => (int) $loan->fresh()->grace_cycles,
                'first_repayment' => sprintf(
                    '%d/%d',
                    (int) $loan->fresh()->first_repayment_month,
                    (int) $loan->fresh()->first_repayment_year,
                ),
            ]);
        });

        return $unpaid->count();
    }

    private function shiftInstallmentCycles(LoanInstallment $installment, int $cycles): void
    {
        if ($installment->due_date === null) {
            return;
        }

        if ($cycles < 1) {
            throw new InvalidArgumentException(__('Grace cycles must be at least one.'));
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

    private function applyLoanGraceMetadata(Loan $loan, int $graceCycles): void
    {
        $updates = [
            'grace_cycles' => $graceCycles,
            'has_grace_cycle' => $graceCycles > 0,
        ];

        if ($loan->first_repayment_month !== null && $loan->first_repayment_year !== null) {
            $firstNext = Carbon::create(
                (int) $loan->first_repayment_year,
                (int) $loan->first_repayment_month,
                1,
            )->addMonthsNoOverflow($graceCycles);
            $updates['first_repayment_month'] = (int) $firstNext->month;
            $updates['first_repayment_year'] = (int) $firstNext->year;
        }

        if ($loan->exempted_month !== null && $loan->exempted_year !== null) {
            $exNext = Carbon::create(
                (int) $loan->exempted_year,
                (int) $loan->exempted_month,
                1,
            )->addMonthsNoOverflow($graceCycles);
            $updates['exempted_month'] = (int) $exNext->month;
            $updates['exempted_year'] = (int) $exNext->year;
        } elseif ($loan->disbursed_at !== null && $graceCycles > 0) {
            $exemption = Loan::computeExemptionAndFirstRepayment(
                Carbon::parse($loan->disbursed_at),
                $graceCycles,
            );
            $updates['exempted_month'] = $exemption['exempted_month'];
            $updates['exempted_year'] = $exemption['exempted_year'];
        }

        if ($loan->due_date !== null) {
            $updates['due_date'] = Carbon::parse($loan->due_date)->addMonthsNoOverflow($graceCycles)->toDateString();
        }

        $loan->update($updates);
    }
}
