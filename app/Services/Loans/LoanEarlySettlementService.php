<?php

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Notifications\Tenant\LoanEarlySettledNotification;
use App\Services\ContributionCycleService;
use App\Support\BusinessDay;
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
        $days = $this->lateFees->daysPastDue($deadline, BusinessDay::now());

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
        $balance = $loan->member->getCashBalance();
        $required = $this->requiredCash($loan);

        return $required <= 0.00001 || $balance >= $required - 0.00001;
    }

    /**
     * Apply early settlement for a lump sum. Full payoff when amount covers all remaining installments;
     * otherwise applies partial settlement with the chosen schedule option.
     *
     * @param  'roll_up'|'skip_future'  $option
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function settle(Loan $loan, float $amount, string $option = 'roll_up', bool $sendNotification = true): void
    {
        $required = $this->requiredCash($loan);

        if ($amount >= $required - 0.00001) {
            $this->earlySettle($loan, $sendNotification);

            return;
        }

        $this->partialEarlySettle($loan, $amount, $option);
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
        $cash = $member->getCashBalance();

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
                    'paid_at' => BusinessDay::now(),
                    'is_late' => $isLate,
                    'late_fee_amount' => $lateFee > 0 ? $lateFee : 0,
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
                'settled_at' => BusinessDay::now(),
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

    /**
     * Apply a partial early settlement: pay down principal with optional schedule roll-up or skip.
     *
     * @param  'roll_up'|'skip_future'  $option
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function partialEarlySettle(Loan $loan, float $amount, string $option = 'roll_up'): void
    {
        if ($loan->status !== 'active') {
            throw new \InvalidArgumentException(__('Only active loans can receive partial early settlement.'));
        }

        if ($amount <= 0.00001) {
            throw new \InvalidArgumentException(__('Settlement amount must be greater than zero.'));
        }

        if (! in_array($option, ['roll_up', 'skip_future'], true)) {
            throw new \InvalidArgumentException(__('Invalid partial settlement option.'));
        }

        $loan->loadMissing(['member', 'installments']);
        $member = $loan->member;
        $member->unsetRelation('accounts');
        $cash = $member->getCashBalance();

        if ($cash < $amount - 0.00001) {
            throw new \RuntimeException(__('Insufficient cash for partial settlement.'));
        }

        $pending = $this->pendingInstallments($loan);
        if ($pending->isEmpty()) {
            throw new \InvalidArgumentException(__('This loan has no unpaid installments.'));
        }

        DB::transaction(function () use ($loan, $member, $amount, $option, $pending): void {
            $remaining = $amount;

            foreach ($pending as $installment) {
                if ($remaining <= 0.00001) {
                    break;
                }

                $required = $this->installmentCashRequired($installment);
                $pay = min($remaining, $required);

                if ($pay <= 0.00001) {
                    continue;
                }

                $lateFee = min($this->lateFeeForInstallment($installment), $pay);
                $principal = $pay - $lateFee;

                $this->ledger->debitCashForRepayment($member, $installment, $lateFee, null, $principal);

                $newCollected = (float) ($installment->amount_collected ?? 0) + $principal;

                if ($newCollected >= (float) $installment->amount - 0.00001) {
                    $installment->update([
                        'status' => 'paid',
                        'paid_at' => BusinessDay::now(),
                        'amount_collected' => (float) $installment->amount,
                        'collection_status' => 'collected',
                        'late_fee_amount' => $lateFee > 0 ? $lateFee : 0,
                    ]);
                } else {
                    $installment->update([
                        'amount_collected' => $newCollected,
                        'collection_status' => 'partially_pending',
                    ]);
                }

                $remaining -= $pay;
            }

            if ($option === 'roll_up' && $remaining > 0.00001) {
                $last = $loan->installments()
                    ->whereIn('status', ['pending', 'overdue'])
                    ->orderByDesc('due_date')
                    ->first();

                if ($last) {
                    $last->update([
                        'amount' => round((float) $last->amount + $remaining, 2),
                    ]);
                }
            } elseif ($option === 'skip_future' && $remaining > 0.00001) {
                $toRemove = $loan->installments()
                    ->whereIn('status', ['pending', 'overdue'])
                    ->orderBy('due_date')
                    ->get()
                    ->reverse()
                    ->values();

                foreach ($toRemove as $installment) {
                    if ($remaining <= 0.00001) {
                        break;
                    }

                    $instAmount = (float) $installment->amount - (float) ($installment->amount_collected ?? 0);

                    if ($instAmount <= $remaining + 0.00001) {
                        $installment->delete();
                        $remaining -= $instAmount;
                    }
                }
            }

            $loan->refresh();
            $loan->syncPaidOffStatusFromInstallments();
        });
    }
}
