<?php

namespace App\Services\Loans;

use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Notifications\Tenant\LoanEarlySettledNotification;
use App\Services\ContributionCycleService;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
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
        protected LoanRepaymentLogService $repaymentLog,
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
    public function earlySettle(Loan $loan, bool $sendNotification = true, ?CarbonInterface $transactedAt = null): void
    {
        if ($loan->status !== 'active') {
            throw new \InvalidArgumentException('Only active loans can be early settled.');
        }

        $at = $transactedAt ?? BusinessDay::now();

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
            $currency = Setting::get('general', 'currency', 'USD');

            throw new \RuntimeException(
                __('Insufficient cash. Required: :required (installments plus any late fees). Current cash balance: :balance.', [
                    'required' => MoneyDisplay::format($required, $currency) ?? '',
                    'balance' => MoneyDisplay::format($cash, $currency) ?? '',
                ])
            );
        }

        LoanRepaymentLogService::runSuppressingInstallmentLogs(function () use ($loan, $pending, $member, $at, $required): void {
            DB::transaction(function () use ($loan, $pending, $member, $at, $required): void {
                foreach ($pending as $installment) {
                    $due = $installment->due_date;
                    $m = (int) $due->month;
                    $y = (int) $due->year;
                    $isLate = $this->cycle->isLate($m, $y);
                    $lateFee = $this->lateFeeForInstallment($installment);

                    $this->ledger->debitCashForRepayment($member, $installment, $lateFee, $at);

                    $installment->update([
                        'status' => 'paid',
                        'paid_at' => $at,
                        'amount_collected' => (float) $installment->amount,
                        'collection_status' => 'collected',
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

                $this->repaymentLog->recordSettlementRepayment($loan, $required, 'full', null, $at);

                $loan->refresh();
                $loan->update([
                    'status' => 'early_settled',
                    'settled_at' => $at,
                ]);
            });
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
     * Apply a partial early settlement: pay down principal with schedule roll-up (compress) or skip cycles.
     *
     * Roll-up marks covered installments paid and removes the same count from the tail of the schedule.
     * Skip cycles marks covered installments as skipped (waived) while preserving the original schedule length.
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

        $loan->loadMissing(['member', 'installments', 'loanTier']);
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

        $minEmi = $this->representativeMinEmi($loan, $pending);
        if ($amount < $minEmi - 0.00001) {
            $currency = Setting::get('general', 'currency', 'USD');

            throw new \InvalidArgumentException(__('Partial settlement must cover at least one full EMI (:amount).', [
                'amount' => MoneyDisplay::format($minEmi, $currency) ?? number_format($minEmi, 2),
            ]));
        }

        LoanRepaymentLogService::runSuppressingInstallmentLogs(function () use ($loan, $member, $amount, $option, $pending): void {
            $at = BusinessDay::now();

            DB::transaction(function () use ($loan, $member, $amount, $option, $pending, $at): void {
                if ($option === 'roll_up') {
                    $this->applyRollUpPartialSettlement($loan, $member, $amount, $pending, $at);
                } else {
                    $this->applySkipCyclesPartialSettlement($loan, $member, $amount, $pending, $at);
                }

                $this->repaymentLog->recordSettlementRepayment($loan, $amount, 'partial', $option, $at);

                $loan->refresh();
                $loan->syncPaidOffStatusFromInstallments();
            });
        });
    }

    /**
     * @param  Collection<int, LoanInstallment>  $pending
     */
    private function applyRollUpPartialSettlement(
        Loan $loan,
        Member $member,
        float $amount,
        Collection $pending,
        CarbonInterface $at,
    ): void {
        $remaining = $amount;
        $cyclesSettled = 0;

        foreach ($pending as $installment) {
            if ($remaining <= 0.00001) {
                break;
            }

            $required = $this->installmentCashRequired($installment);

            if ($remaining < $required - 0.00001) {
                $this->applyPartialPaymentToInstallment($loan, $member, $installment, $remaining, $at);

                break;
            }

            $this->applyFullPaymentToInstallment($loan, $member, $installment, $at);
            $remaining -= $required;
            $cyclesSettled++;
        }

        if ($cyclesSettled > 0) {
            $this->compressScheduleTail($loan, $cyclesSettled);
        }
    }

    /**
     * @param  Collection<int, LoanInstallment>  $pending
     */
    private function applySkipCyclesPartialSettlement(
        Loan $loan,
        Member $member,
        float $amount,
        Collection $pending,
        CarbonInterface $at,
    ): void {
        $minEmi = $this->representativeMinEmi($loan, $pending);
        $cyclesToSkip = (int) floor($amount / $minEmi);
        $remainder = round($amount - ($cyclesToSkip * $minEmi), 2);

        foreach ($pending->take($cyclesToSkip) as $installment) {
            $this->applySkippedCyclePayment($loan, $member, $installment, $at);
        }

        if ($remainder > 0.00001) {
            $next = $pending->skip($cyclesToSkip)->first();

            if ($next instanceof LoanInstallment) {
                $this->applyPartialPaymentToInstallment($loan, $member, $next, $remainder, $at);
            }
        }
    }

    private function applyFullPaymentToInstallment(
        Loan $loan,
        Member $member,
        LoanInstallment $installment,
        CarbonInterface $at,
    ): void {
        $due = $installment->due_date;
        $isLate = $this->cycle->isLate((int) $due->month, (int) $due->year);
        $lateFee = $this->lateFeeForInstallment($installment);

        $this->ledger->debitCashForRepayment($member, $installment, $lateFee, $at);

        $installment->update([
            'status' => 'paid',
            'paid_at' => $at,
            'amount_collected' => (float) $installment->amount,
            'collection_status' => 'collected',
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

    private function applySkippedCyclePayment(
        Loan $loan,
        Member $member,
        LoanInstallment $installment,
        CarbonInterface $at,
    ): void {
        $lateFee = $this->lateFeeForInstallment($installment);
        $principal = (float) $installment->amount;

        $this->ledger->debitCashForRepayment($member, $installment, $lateFee, $at);
        $this->ledger->postLoanRepayment($installment);

        $installment->update([
            'status' => 'waived',
            'waived_at' => $at,
            'amount_collected' => $principal,
            'collection_status' => 'collected',
            'is_late' => false,
            'late_fee_amount' => $lateFee > 0 ? $lateFee : 0,
        ]);
    }

    private function applyPartialPaymentToInstallment(
        Loan $loan,
        Member $member,
        LoanInstallment $installment,
        float $pay,
        CarbonInterface $at,
    ): void {
        $lateFee = min($this->lateFeeForInstallment($installment), $pay);
        $principal = $pay - $lateFee;

        $this->ledger->debitCashForRepayment($member, $installment, $lateFee, $at, $principal);

        $newCollected = (float) ($installment->amount_collected ?? 0) + $principal;

        if ($newCollected >= (float) $installment->amount - 0.00001) {
            $installment->update([
                'status' => 'paid',
                'paid_at' => $at,
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
    }

    private function compressScheduleTail(Loan $loan, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $loan->installments()
            ->whereIn('status', ['pending', 'overdue'])
            ->orderByDesc('installment_number')
            ->limit($count)
            ->get()
            ->each(fn (LoanInstallment $installment): ?bool => $installment->delete());

        $this->renumberInstallments($loan);
    }

    private function renumberInstallments(Loan $loan): void
    {
        $loan->installments()
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->values()
            ->each(function (LoanInstallment $installment, int $index): void {
                $number = $index + 1;

                if ((int) $installment->installment_number !== $number) {
                    $installment->update(['installment_number' => $number]);
                }
            });

        $lastDueDate = $loan->installments()->max('due_date');

        $loan->update([
            'installments_count' => $loan->installments()->count(),
            'due_date' => $lastDueDate,
        ]);
    }

    /**
     * @param  Collection<int, LoanInstallment>  $pending
     */
    private function representativeMinEmi(Loan $loan, Collection $pending): float
    {
        $min = (float) ($loan->loanTier?->min_monthly_installment ?? $loan->monthly_repayment ?? 0);

        if ($min > 0.00001) {
            return $min;
        }

        return (float) $pending->first()->amount;
    }
}
