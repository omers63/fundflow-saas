<?php

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Notifications\Tenant\LoanEarlySettledNotification;
use App\Services\ContributionCycleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pay off all remaining installments at once from the member cash account (principal + any late fees).
 */
class LoanEarlySettlementService
{
    public function __construct(
        protected LoanLedgerService $ledger,
        protected ContributionCycleService $cycle,
        protected LateFeeService $lateFees,
    ) {}

    /** Pending/overdue installments in due-date order. */
    public function pendingInstallments(Loan $loan): Collection
    {
        return $loan->installments()
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('due_date')
            ->get();
    }

    /** Late fee (SAR) for one installment if its cycle deadline has passed. */
    public function lateFeeForInstallment(LoanInstallment $installment): float
    {
        $due = $installment->due_date;
        $m = (int) $due->month;
        $y = (int) $due->year;
        $deadline = $this->cycle->deadline($m, $y);
        $days = $this->lateFees->daysPastDue($deadline, now());

        return $this->lateFees->repaymentLateFeeForDays($days);
    }

    public function installmentCashRequired(LoanInstallment $installment): float
    {
        return (float) $installment->amount + $this->lateFeeForInstallment($installment);
    }

    /** Total cash required to settle all remaining installments (principal + late fees). */
    public function requiredCash(Loan $loan): float
    {
        $total = 0.0;
        foreach ($this->pendingInstallments($loan) as $inst) {
            $total += $this->installmentCashRequired($inst);
        }

        return round($total, 2);
    }

    public function hasSufficientCash(Loan $loan): bool
    {
        $loan->member->unsetRelation('accounts');
        $balance = (float) $loan->member->cash_balance;
        $required = $this->requiredCash($loan);

        return $required <= 0.00001 || $balance >= $required - 0.00001;
    }

    /**
     * Debits member cash for each remaining installment (with late fees), posts repayments via observer,
     * then sets loan status to early_settled.
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function earlySettle(Loan $loan, bool $sendNotification = true): void
    {
        if ($loan->status !== 'active') {
            throw new \InvalidArgumentException('Only active loans can be early settled.');
        }

        $loan->loadMissing(['member.user', 'installments']);

        $pending = $this->pendingInstallments($loan);
        if ($pending->isEmpty()) {
            throw new \InvalidArgumentException('This loan has no unpaid installments.');
        }

        $required = $this->requiredCash($loan);
        $member = $loan->member;
        $member->unsetRelation('accounts');
        $cash = (float) $member->cash_balance;

        if ($cash < $required - 0.00001) {
            throw new \RuntimeException(
                'Insufficient cash. Required: SAR '.number_format($required, 2)
                .' (installments plus any late fees). Current cash balance: SAR '.number_format($cash, 2).'.'
            );
        }

        DB::transaction(function () use ($loan, $pending, $member) {
            foreach ($pending as $installment) {
                $due = $installment->due_date;
                $m = (int) $due->month;
                $y = (int) $due->year;
                $isLate = $this->cycle->isLate($m, $y);
                $lateFee = $this->lateFeeForInstallment($installment);

                $this->ledger->debitCashForRepayment($member, $installment, $lateFee);

                $installment->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'is_late' => $isLate,
                    'late_fee_amount' => $lateFee > 0 ? $lateFee : null,
                ]);

                if ($isLate) {
                    $amt = (float) $installment->amount;
                    $loan->increment('late_repayment_count');
                    $loan->increment('late_repayment_amount', $amt);
                    $member->increment('late_repayment_count');
                    $member->increment('late_repayment_amount', $amt);
                }
            }

            $loan->refresh();
            $loan->update([
                'status' => 'early_settled',
                'settled_at' => now(),
            ]);
        });

        $loan->refresh();

        if ($loan->fund_tier_id !== null) {
            LoanQueueOrderingService::resequenceFundTier((int) $loan->fund_tier_id);
        }

        if ($sendNotification) {
            try {
                $loan->member->user->notify(new LoanEarlySettledNotification($loan));
            } catch (\Throwable) {
            }
        }
    }
}
