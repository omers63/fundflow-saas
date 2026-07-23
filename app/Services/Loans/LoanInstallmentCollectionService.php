<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Exceptions\InsufficientMemberCashForCollectionException;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Support\BusinessDay;
use App\Support\InstallmentCollectionStatus;
use App\Support\LegacyImportedLoan;
use Illuminate\Support\Facades\DB;

/**
 * Incremental EMI collection when member cash increases (mirrors contribution collection engine).
 */
class LoanInstallmentCollectionService
{
    public function __construct(
        protected LoanLedgerService $ledger,
        protected LateFeeService $lateFees,
        protected ContributionCycleService $cycles,
    ) {}

    public function onMemberCashIncreased(Member $member): void
    {
        [$month, $year] = $this->cycles->currentOpenPeriod();

        $this->collectOpenInstallments($member, $month, $year, throughOpenPeriod: true);
    }

    public function onMemberCashIncreasedForPeriod(
        Member $member,
        int $month,
        int $year,
        bool $throughSelectedPeriod = false,
    ): void {
        $this->collectOpenInstallments($member, $month, $year, throughOpenPeriod: $throughSelectedPeriod);
    }

    protected function collectOpenInstallments(
        Member $member,
        ?int $month = null,
        ?int $year = null,
        bool $throughOpenPeriod = false,
    ): void {
        $query = LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->where(function ($q): void {
                $q->whereNull('collection_status')
                    ->orWhereIn('collection_status', InstallmentCollectionStatus::openCollectionStates());
            })
            ->whereHas('loan', function ($q) use ($member): void {
                $q->whereIn('status', ['active', 'transferred'])
                    ->where('member_id', $member->id);
            });

        if ($month !== null && $year !== null) {
            if ($throughOpenPeriod) {
                $query->whereDate('due_date', '<=', $this->cycles->cycleDueEndAt($month, $year)->toDateString());
            } else {
                [$start, $end] = $this->cycles->cycleDueDateBounds($month, $year);
                $query->whereBetween('due_date', [$start, $end]);
            }
        }

        $installments = $query->orderBy('due_date')->get();

        foreach ($installments as $installment) {
            $member = $member->fresh() ?? $member;
            $member->unsetRelation('accounts');

            $this->attemptCollection($installment, $member);
        }
    }

    public function attemptCollection(LoanInstallment $installment, ?Member $member = null): string
    {
        if ($installment->isPaid()) {
            return 'paid';
        }

        $installment->loadMissing('loan.member');
        $loan = $installment->loan;
        $member = $member?->fresh() ?? $loan->member;
        $member->unsetRelation('accounts');

        if (! $loan instanceof Loan || ! in_array($loan->status, ['active', 'transferred'], true)) {
            return 'inactive';
        }

        $loan->ensureScheduleInstallmentAmount($installment);
        $installment->refresh();

        $due = $installment->due_date;
        [$cycleMonth, $cycleYear] = $this->cycles->cyclePeriodForDueDate($due);

        if (Contribution::blocksLoanRepaymentForMemberPeriod($member, $cycleMonth, $cycleYear)) {
            return 'skipped_contribution_cycle';
        }

        $principalRemaining = max(0.0, (float) $installment->amount - (float) ($installment->amount_collected ?? 0));
        $lateFee = $this->outstandingLateFee($installment);
        $required = $principalRemaining + $lateFee;
        $cash = $member->getCashBalance();

        if ($required <= 0.00001) {
            return $this->finalizePaid($installment, $loan, $member, $lateFee);
        }

        if ($cash < $required - 0.00001) {
            if ($cash <= 0.00001) {
                return 'no_cash';
            }

            return $this->postPartialCollection($installment, $member, $cash, $principalRemaining);
        }

        try {
            return $this->postFullCollection($installment, $loan, $member, $principalRemaining, $lateFee);
        } catch (InsufficientMemberCashForCollectionException) {
            $member = $member->fresh() ?? $member;
            $member->unsetRelation('accounts');
            $cash = $member->getCashBalance();

            if ($cash <= 0.00001) {
                return 'no_cash';
            }

            return $this->postPartialCollection($installment, $member, $cash, $principalRemaining);
        }
    }

    public function requiredCashForInstallment(LoanInstallment $installment): float
    {
        if ($installment->isPaid()) {
            return 0.0;
        }

        $principalRemaining = max(
            0.0,
            (float) $installment->amount - (float) ($installment->amount_collected ?? 0),
        );

        return $principalRemaining + $this->outstandingLateFee($installment);
    }

    protected function outstandingLateFee(LoanInstallment $installment): float
    {
        $installment->loadMissing('loan');

        if ($installment->loan !== null && LegacyImportedLoan::isLoan($installment->loan)) {
            return 0.0;
        }

        [$cycleMonth, $cycleYear] = $this->cycles->cyclePeriodForDueDate($installment->due_date);
        $deadline = $this->cycles->deadline($cycleMonth, $cycleYear);
        $days = $this->lateFees->daysPastDue($deadline, BusinessDay::now());
        $computed = $this->lateFees->repaymentLateFeeForDays($days);

        return max($computed, (float) ($installment->late_fee_amount ?? 0));
    }

    protected function postPartialCollection(
        LoanInstallment $installment,
        Member $member,
        float $cash,
        float $principalRemaining,
    ): string {
        $principalPortion = min($principalRemaining, $cash);

        if ($principalPortion <= 0.00001) {
            return 'no_cash';
        }

        DB::transaction(function () use ($installment, $member, $principalPortion): void {
            $this->ledger->debitCashForRepayment($member, $installment, 0, null, $principalPortion);

            $installment->update([
                'amount_collected' => (float) ($installment->amount_collected ?? 0) + $principalPortion,
                'collection_status' => InstallmentCollectionStatus::PARTIALLY_PENDING,
            ]);
        });

        return 'partial';
    }

    protected function postFullCollection(
        LoanInstallment $installment,
        Loan $loan,
        Member $member,
        float $principalRemaining,
        float $lateFee,
    ): string {
        return DB::transaction(function () use ($installment, $loan, $member, $principalRemaining, $lateFee): string {
            $this->ledger->debitCashForRepayment($member, $installment, $lateFee, null, $principalRemaining);

            return $this->finalizePaid($installment, $loan, $member, $lateFee);
        });
    }

    protected function finalizePaid(
        LoanInstallment $installment,
        Loan $loan,
        Member $member,
        float $lateFee,
    ): string {
        $due = $installment->due_date;
        [$cycleMonth, $cycleYear] = $this->cycles->cyclePeriodForDueDate($due);
        $isLate = $this->cycles->isLate($cycleMonth, $cycleYear);

        $installment->update([
            'status' => 'paid',
            'paid_at' => BusinessDay::now(),
            'is_late' => $isLate,
            'late_fee_amount' => $lateFee > 0.00001 ? $lateFee : (float) ($installment->late_fee_amount ?? 0),
            'amount_collected' => (float) $installment->amount,
            'collection_status' => InstallmentCollectionStatus::COLLECTED,
        ]);

        if ($isLate) {
            $amt = (float) $installment->amount;
            $loan->increment('late_repayment_count');
            $loan->increment('late_repayment_amount', $amt);
            $member->increment('late_repayment_count');
            $member->increment('late_repayment_amount', $amt);
        }

        return 'collected';
    }
}
