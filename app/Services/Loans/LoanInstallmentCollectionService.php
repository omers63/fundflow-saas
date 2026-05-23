<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Support\InstallmentCollectionStatus;
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
        LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->where(function ($q): void {
                $q->whereNull('collection_status')
                    ->orWhereIn('collection_status', InstallmentCollectionStatus::openCollectionStates());
            })
            ->whereHas('loan', function ($q) use ($member): void {
                $q->whereIn('status', ['active', 'transferred'])
                    ->where('member_id', $member->id);
            })
            ->orderBy('due_date')
            ->each(fn (LoanInstallment $installment) => $this->attemptCollection($installment));
    }

    public function attemptCollection(LoanInstallment $installment): string
    {
        if ($installment->isPaid()) {
            return 'paid';
        }

        $installment->loadMissing('loan.member');
        $loan = $installment->loan;
        $member = $loan->member;

        if (! $loan instanceof Loan || ! in_array($loan->status, ['active', 'transferred'], true)) {
            return 'inactive';
        }

        $due = $installment->due_date;
        $month = (int) $due->month;
        $year = (int) $due->year;

        if (Contribution::activePeriodExists((int) $member->id, $month, $year)) {
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

        return $this->postFullCollection($installment, $loan, $member, $principalRemaining, $lateFee);
    }

    protected function outstandingLateFee(LoanInstallment $installment): float
    {
        $due = $installment->due_date;
        $deadline = $this->cycles->deadline((int) $due->month, (int) $due->year);
        $days = $this->lateFees->daysPastDue($deadline, now());
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
        $month = (int) $due->month;
        $year = (int) $due->year;
        $isLate = $this->cycles->isLate($month, $year);

        $installment->update([
            'status' => 'paid',
            'paid_at' => now(),
            'is_late' => $isLate,
            'late_fee_amount' => $lateFee > 0.00001 ? $lateFee : ($installment->late_fee_amount ?? null),
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
