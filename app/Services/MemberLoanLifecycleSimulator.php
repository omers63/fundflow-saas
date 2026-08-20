<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Loan;
use App\Support\BusinessDay;
use App\Support\LoanFundExcessDisposition;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Educational what-if simulator for loan lifecycle intent (not live ledger rules).
 *
 * While Active, the member may combine regular payments, partial early settlement
 * (roll-up / skip), and full early settlement at any time.
 *
 * @see docs/loan-lifecycle-simulator-intent-vs-live.md
 */
final class MemberLoanLifecycleSimulator
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAID = 'paid';

    public const STATUS_FULLY_SETTLED = 'fully_settled';

    public const PARTIAL_ROLL_UP = 'roll_up';

    public const PARTIAL_SKIP = 'skip_future';

    /**
     * @param  array{
     *     min_installment: float,
     *     member_portion: float,
     *     master_portion: float,
     *     settlement_amt: float,
     *     total_repay: float,
     *     eligibility_amt: float,
     *     eligibility_base: float,
     *     projected_fund?: float,
     *     excess_fund?: float,
     *     projection?: array{current_fund?: float},
     *     schedule?: array{first_due_date?: string|null, last_due_date?: string|null, rows?: list<array<string, mixed>>}
     * }  $calc
     * @return array<string, mixed>
     */
    public function startFromEstimate(
        array $calc,
        float $loanAmount,
        ?string $assumedStartDate = null,
        ?string $excessDisposition = null,
    ): array {
        $loanAmount = round(max(0.0, $loanAmount), 2);
        $memberPortion = round((float) ($calc['member_portion'] ?? 0), 2);
        $masterPortion = round((float) ($calc['master_portion'] ?? 0), 2);
        $topUp = round((float) ($calc['settlement_amt'] ?? 0), 2);
        $maturity = round((float) ($calc['total_repay'] ?? ($masterPortion + $topUp)), 2);
        $minInstallment = round((float) ($calc['min_installment'] ?? 0), 2);
        $eligibilityAmt = round((float) ($calc['eligibility_amt'] ?? 0), 2);
        $eligibilityBase = round((float) ($calc['eligibility_base'] ?? 0), 2);

        $preLoanFund = round((float) ($calc['projected_fund'] ?? $calc['projection']['current_fund'] ?? 0), 2);
        // Live disbursement debits member fund for both portions; balance may go negative.
        $fundAfterMemberPortion = round($preLoanFund - $memberPortion, 2);
        $positiveFundAfterMember = max(0.0, $fundAfterMemberPortion);
        $excessFund = round(max(0.0, (float) ($calc['excess_fund'] ?? $positiveFundAfterMember)), 2);
        $cashOutExcess = LoanFundExcessDisposition::toCashOutFlag($excessDisposition)
            && $excessFund > 0.00001;
        $excessToCash = $cashOutExcess ? min($excessFund, $positiveFundAfterMember) : 0.0;
        $fundAfterDisbursement = round($fundAfterMemberPortion - $masterPortion - $excessToCash, 2);
        $cashBalance = round($excessToCash, 2);

        $start = $this->resolvedStartDate($assumedStartDate);
        $firstDue = $calc['schedule']['first_due_date'] ?? null;
        $nextDue = is_string($firstDue) && $firstDue !== ''
            ? Carbon::parse($firstDue)->startOfDay()
            : $start->copy()->addMonthNoOverflow()->startOfDay();

        $remainingMonths = $this->monthsForRemaining($maturity, $minInstallment);
        $schedule = $this->seedScheduleFromEstimate($calc, $nextDue, $maturity, $minInstallment);

        $history = [
            $this->historyEvent(
                'disbursed',
                __('Loan disbursed'),
                $loanAmount,
                $preLoanFund,
                $fundAfterDisbursement,
                $masterPortion,
                $maturity,
                0.0,
                null,
                null,
                $start->toDateString(),
            ),
        ];

        if ($excessToCash > 0.00001) {
            $history[] = $this->historyEvent(
                'excess_to_cash',
                __('Excess fund transferred to cash'),
                $excessToCash,
                round($fundAfterMemberPortion - $masterPortion, 2),
                $fundAfterDisbursement,
                $masterPortion,
                $maturity,
                0.0,
                $masterPortion,
                $maturity,
                $start->toDateString(),
            );
        }

        $state = [
            'status' => self::STATUS_ACTIVE,
            'loan_amount' => $loanAmount,
            'member_portion' => $memberPortion,
            'master_portion' => $masterPortion,
            'top_up' => $topUp,
            'maturity_amount' => $maturity,
            'min_installment' => $minInstallment,
            'eligibility_amt' => $eligibilityAmt,
            'eligibility_base' => $eligibilityBase,
            'pre_loan_fund' => $preLoanFund,
            'fund_balance' => $fundAfterDisbursement,
            'cash_balance' => $cashBalance,
            'cash_out_excess_fund' => $cashOutExcess,
            'excess_to_cash' => $excessToCash,
            'outstanding_fund_portion' => $masterPortion,
            'total_repaid' => 0.0,
            'remaining_maturity' => $maturity,
            'remaining_months' => $remainingMonths,
            'next_due_date' => $nextDue->toDateString(),
            'expected_maturity_date' => $this->expectedMaturityDate($nextDue, $maturity, $minInstallment),
            'full_settlement_amount' => 0.0,
            'eligible_for_new_loan' => false,
            'schedule_rows' => $schedule,
            'history' => $history,
        ];

        return $this->withDerived($state);
    }

    /**
     * Regular / additional payment — recalculates remaining maturity and rebuilds the pending schedule.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function applyRegularPayment(array $state, float $amount): array
    {
        $state = $this->normalize($state);
        $this->assertActive($state, __('Regular payments are only available while the simulated loan is active.'));

        $amount = round($amount, 2);
        $min = (float) $state['min_installment'];

        if ($amount <= 0.00001) {
            throw new InvalidArgumentException(__('Payment amount must be greater than zero.'));
        }

        if ($min > 0.00001 && $amount + 0.00001 < $min) {
            throw new InvalidArgumentException(__('Regular payments must be at least the minimum installment.'));
        }

        $fundBefore = (float) $state['fund_balance'];
        $outstandingBefore = (float) $state['outstanding_fund_portion'];
        $remainingBefore = (float) $state['remaining_maturity'];
        $eventAt = $this->nextPendingDueDate($state);
        $state = $this->applyAmountTowardMaturity($state, $amount);

        $state['history'][] = $this->historyEvent(
            'regular_payment',
            __('Regular payment'),
            $amount,
            $fundBefore,
            (float) $state['fund_balance'],
            (float) $state['outstanding_fund_portion'],
            (float) $state['remaining_maturity'],
            (float) $state['total_repaid'],
            $outstandingBefore,
            $remainingBefore,
            $eventAt,
        );

        if ($state['remaining_maturity'] <= 0.00001) {
            return $this->markPaid($state);
        }

        return $this->afterRegularPaymentSettlement($state, $amount);
    }

    /**
     * Partial early settlement (roll-up or skip) while Active.
     *
     * @param  array<string, mixed>  $state
     * @param  'roll_up'|'skip_future'  $option
     * @return array<string, mixed>
     */
    public function applyPartialEarlySettlement(array $state, float $amount, string $option = self::PARTIAL_ROLL_UP): array
    {
        $state = $this->normalize($state);
        $this->assertActive($state, __('Partial early settlement is only available while the simulated loan is active.'));

        if (! in_array($option, [self::PARTIAL_ROLL_UP, self::PARTIAL_SKIP], true)) {
            throw new InvalidArgumentException(__('Invalid partial early settlement option.'));
        }

        $amount = round($amount, 2);
        $min = (float) $state['min_installment'];

        if ($amount <= 0.00001) {
            throw new InvalidArgumentException(__('Settlement amount must be greater than zero.'));
        }

        $covered = $min > 0.00001 ? (int) floor($amount / $min) : 0;
        $fundBefore = (float) $state['fund_balance'];
        $outstandingBefore = (float) $state['outstanding_fund_portion'];
        $remainingBefore = (float) $state['remaining_maturity'];
        $eventAt = $this->nextPendingDueDate($state);
        $state = $this->applyAmountTowardMaturity($state, $amount);

        $state['history'][] = $this->historyEvent(
            $option === self::PARTIAL_ROLL_UP ? 'partial_roll_up' : 'partial_skip',
            $option === self::PARTIAL_ROLL_UP
                ? __('Partial early settlement (roll up)')
                : __('Partial early settlement (skip installments)'),
            $amount,
            $fundBefore,
            (float) $state['fund_balance'],
            (float) $state['outstanding_fund_portion'],
            (float) $state['remaining_maturity'],
            (float) $state['total_repaid'],
            $outstandingBefore,
            $remainingBefore,
            $eventAt,
        );

        if ($state['remaining_maturity'] <= 0.00001) {
            return $this->markPaid($state);
        }

        if ($option === self::PARTIAL_SKIP) {
            return $this->afterSkipSettlement($state, $covered);
        }

        return $this->afterRollUpSettlement($state, $covered, $amount);
    }

    /**
     * Full early settlement — available anytime while Active (including after regular / partial payments).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function applyFullEarlySettlement(array $state): array
    {
        $state = $this->normalize($state);
        $this->assertActive($state, __('Full early settlement is only available while the simulated loan is active.'));

        $required = $this->fullSettlementAmount($state);
        $fundBefore = (float) $state['fund_balance'];
        $outstandingBefore = (float) $state['outstanding_fund_portion'];
        $remainingBefore = (float) $state['remaining_maturity'];
        $eventAt = $this->nextPendingDueDate($state);
        $state['fund_balance'] = round((float) $state['pre_loan_fund'], 2);
        $state['outstanding_fund_portion'] = 0.0;
        $state['remaining_maturity'] = 0.0;
        $state['remaining_months'] = 0;
        $state['next_due_date'] = null;
        $state['expected_maturity_date'] = $eventAt;
        $state['total_repaid'] = round((float) $state['total_repaid'] + $required, 2);
        $state['status'] = self::STATUS_FULLY_SETTLED;
        $state['schedule_rows'] = $this->rollUpFullSettlementSchedule(
            is_array($state['schedule_rows'] ?? null) ? $state['schedule_rows'] : [],
            $required,
        );
        $state['history'][] = $this->historyEvent(
            'full_early_settlement',
            __('Full early settlement'),
            $required,
            $fundBefore,
            (float) $state['fund_balance'],
            0.0,
            0.0,
            (float) $state['total_repaid'],
            $outstandingBefore,
            $remainingBefore,
            $eventAt,
        );

        return $this->withDerived($state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function applyContribution(array $state, float $amount): array
    {
        $state = $this->normalize($state);

        if (! in_array($state['status'], [self::STATUS_PAID, self::STATUS_FULLY_SETTLED], true)) {
            throw new InvalidArgumentException(__('Contributions in the simulator apply after the loan is closed.'));
        }

        $amount = round($amount, 2);

        if ($amount <= 0.00001) {
            throw new InvalidArgumentException(__('Contribution amount must be greater than zero.'));
        }

        $fundBefore = (float) $state['fund_balance'];
        $state['fund_balance'] = round((float) $state['fund_balance'] + $amount, 2);
        $state['history'][] = $this->historyEvent(
            'contribution',
            __('Post-loan contribution'),
            $amount,
            $fundBefore,
            (float) $state['fund_balance'],
            (float) $state['outstanding_fund_portion'],
            (float) $state['remaining_maturity'],
            (float) $state['total_repaid'],
        );

        return $this->withDerived($state);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function fullSettlementAmount(array $state): float
    {
        $state = $this->normalize($state);

        return round(
            max(0.0, (float) $state['pre_loan_fund'] - (float) $state['fund_balance']),
            2,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function withDerived(array $state): array
    {
        $state = $this->normalize($state);
        $state['full_settlement_amount'] = $state['status'] === self::STATUS_ACTIVE
            ? $this->fullSettlementAmount($state)
            : 0.0;
        $state['eligible_for_new_loan'] = in_array($state['status'], [self::STATUS_PAID, self::STATUS_FULLY_SETTLED], true)
            && (float) $state['fund_balance'] + 0.00001 >= (float) $state['eligibility_amt'];
        $state['status_label'] = match ($state['status']) {
            self::STATUS_PAID => __('Paid (normal maturity)'),
            self::STATUS_FULLY_SETTLED => __('Fully settled'),
            default => __('Active'),
        };
        $rows = is_array($state['schedule_rows'] ?? null) ? $state['schedule_rows'] : [];
        $state['pending_count'] = count(array_filter(
            $rows,
            fn (array $row): bool => ($row['kind'] ?? '') === 'pending',
        ));
        $state['schedule_count'] = count(array_filter(
            $rows,
            fn (array $row): bool => ! in_array((string) ($row['kind'] ?? ''), [
                'grace',
                'contribution_due',
                'contribution_paid',
            ], true),
        ));

        return $state;
    }

    /**
     * Preview which schedule cycles a payment / settlement would target.
     *
     * @param  array<string, mixed>  $state
     * @return array{
     *     regular: array{count: int, summary: string, cycles: list<array{number: int|null, cycle_label: string, due_label: string}>},
     *     partial: array{count: int, summary: string, cycles: list<array{number: int|null, cycle_label: string, due_label: string}>, dropped_summary: string|null, dropped: list<array{number: int|null, cycle_label: string, due_label: string}>},
     *     full: array{count: int, summary: string, cycles: list<array{number: int|null, cycle_label: string, due_label: string}>}
     * }
     */
    public function actionTargets(array $state, float $paymentAmount, string $partialOption = self::PARTIAL_ROLL_UP): array
    {
        $state = $this->normalize($state);
        $rows = is_array($state['schedule_rows'] ?? null) ? $state['schedule_rows'] : [];
        $pending = array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row['kind'] ?? '') === 'pending',
        ));
        $min = (float) $state['min_installment'];
        $paymentAmount = round(max(0.0, $paymentAmount), 2);
        $covered = 0;

        if ($min > 0.00001 && $paymentAmount + 0.00001 >= $min) {
            $covered = min(count($pending), max(1, (int) floor($paymentAmount / $min)));
        }

        $regularCycles = array_slice($pending, 0, $covered);
        $partialCycles = $regularCycles;
        $droppedCycles = [];
        $fullCycles = $pending;

        return [
            'regular' => [
                'count' => count($regularCycles),
                'summary' => $this->cycleTargetSummary($regularCycles),
                'cycles' => $this->cycleTargetList($regularCycles),
            ],
            'partial' => [
                'count' => count($partialCycles),
                'summary' => $this->cycleTargetSummary($partialCycles),
                'cycles' => $this->cycleTargetList($partialCycles),
                'dropped_summary' => $droppedCycles === [] ? null : $this->cycleTargetSummary($droppedCycles),
                'dropped' => $this->cycleTargetList($droppedCycles),
            ],
            'full' => [
                'count' => count($fullCycles),
                'summary' => $this->cycleTargetSummary($fullCycles),
                'cycles' => $this->cycleTargetList($fullCycles),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     * @return list<array{number: int|null, cycle_label: string, due_label: string}>
     */
    private function cycleTargetList(array $cycles): array
    {
        return array_map(fn (array $row): array => [
            'number' => isset($row['number']) ? (int) $row['number'] : null,
            'cycle_label' => (string) ($row['cycle_label'] ?? '—'),
            'due_label' => (string) ($row['due_label'] ?? ($row['due_date'] ?? '—')),
        ], $cycles);
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     */
    private function cycleTargetSummary(array $cycles): string
    {
        if ($cycles === []) {
            return __('None');
        }

        $first = $cycles[0];
        $last = $cycles[array_key_last($cycles)];
        $firstLabel = (string) ($first['cycle_label'] ?? '—');
        $lastLabel = (string) ($last['cycle_label'] ?? '—');

        if (count($cycles) === 1 || $firstLabel === $lastLabel) {
            return $firstLabel;
        }

        return __(':first → :last (:count cycles)', [
            'first' => $firstLabel,
            'last' => $lastLabel,
            'count' => count($cycles),
        ]);
    }

    /**
     * Path A compatibility alias.
     *
     * @deprecated Use applyRegularPayment()
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function applyPathAPayment(array $state, float $amount): array
    {
        return $this->applyRegularPayment($state, $amount);
    }

    /**
     * @deprecated Use applyFullEarlySettlement()
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function applyPathBFullSettlement(array $state): array
    {
        return $this->applyFullEarlySettlement($state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function applyAmountTowardMaturity(array $state, float $amount): array
    {
        $remainingBefore = (float) $state['remaining_maturity'];
        $applied = min($amount, $remainingBefore);
        $overpay = round(max(0.0, $amount - $applied), 2);

        $toFundPortion = min($applied, (float) $state['outstanding_fund_portion']);
        $state['outstanding_fund_portion'] = round((float) $state['outstanding_fund_portion'] - $toFundPortion, 2);
        // Repayments credit member fund (same as live EMI collection), so the simulated
        // balance climbs from its post-disbursement (often negative) position.
        $state['fund_balance'] = round((float) $state['fund_balance'] + $applied + $overpay, 2);
        $state['total_repaid'] = round((float) $state['total_repaid'] + $amount, 2);
        $state['remaining_maturity'] = round(max(0.0, (float) $state['maturity_amount'] - (float) $state['total_repaid']), 2);

        return $state;
    }

    /**
     * Regular payment clears front pending cycles at the full EMI face value. When the
     * next cycle was already adjusted below a full EMI, that shortfall moves onto the
     * following pending cycle after the regular installment is applied.
     *
     * Example: partial left Mar at 1500; regular 2500 pays Mar at full EMI (2500) and
     * leaves Apr at 1500.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function afterRegularPaymentSettlement(array $state, float $amount): array
    {
        $rows = is_array($state['schedule_rows'] ?? null) ? $state['schedule_rows'] : [];
        $min = round((float) $state['min_installment'], 2);
        $remainingPayment = round($amount, 2);

        foreach ($this->pendingIndexes($rows) as $index) {
            $due = round((float) ($rows[$index]['amount'] ?? 0), 2);

            if ($due <= 0.00001) {
                continue;
            }

            // One regular installment clears one cycle at full EMI, even if that cycle
            // had been reduced — the shortfall is redistributed onto the next pending.
            $paidAmount = $min > 0.00001 ? $min : $due;

            if ($remainingPayment + 0.00001 < $paidAmount) {
                break;
            }

            $rows[$index]['kind'] = 'paid';
            $rows[$index]['amount'] = $paidAmount;
            $rows[$index]['note'] = __('Paid');
            $rows[$index]['days_until_due'] = null;
            $remainingPayment = round($remainingPayment - $paidAmount, 2);

            if ($remainingPayment <= 0.00001) {
                break;
            }
        }

        return $this->syncScheduleAfterSettlement($state, $rows);
    }

    /**
     * Roll-up applies the lump settlement to the earliest pending cycle when at least one
     * installment is fully covered, then drops (covered − 1) rows from the schedule tail.
     * Amounts below one installment (or any remainder after full EMIs) only reduce remaining
     * maturity so the last pending installment is adjusted via redistribution.
     *
     * Example: 10k with 2.5k EMI → Feb shows 10k rolled up, last 3 EMI rows removed.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function afterRollUpSettlement(array $state, int $covered, float $amount): array
    {
        $rows = is_array($state['schedule_rows'] ?? null) ? $state['schedule_rows'] : [];
        $pendingIndexes = $this->pendingIndexes($rows);
        $pendingCount = count($pendingIndexes);
        $covered = min(max(0, $covered), $pendingCount);

        if ($pendingIndexes === []) {
            return $this->withDerived($state);
        }

        if ($covered <= 0) {
            return $this->syncScheduleAfterSettlement($state, $rows);
        }

        $anchorIndex = $pendingIndexes[0];
        $rows[$anchorIndex]['kind'] = 'rolled_up';
        $rows[$anchorIndex]['amount'] = round($amount, 2);
        $rows[$anchorIndex]['note'] = __('Rolled up');
        $rows[$anchorIndex]['days_until_due'] = null;

        $dropCount = min($covered - 1, $pendingCount - 1);
        if ($dropCount > 0) {
            $dropIndexes = array_fill_keys(array_slice($pendingIndexes, -$dropCount), true);
            $rows = array_values(array_filter(
                $rows,
                fn (array $row, int $index): bool => ! isset($dropIndexes[$index]),
                ARRAY_FILTER_USE_BOTH,
            ));
        }

        return $this->syncScheduleAfterSettlement($state, $rows);
    }

    /**
     * Full early settlement rolls the settlement amount onto the next pending cycle and
     * drops the remaining pending rows from the schedule tail (same shape as partial roll-up).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function rollUpFullSettlementSchedule(array $rows, float $amount): array
    {
        $pendingIndexes = $this->pendingIndexes($rows);

        if ($pendingIndexes === []) {
            return $rows;
        }

        $anchorIndex = $pendingIndexes[0];
        $rows[$anchorIndex]['kind'] = 'cancelled';
        $rows[$anchorIndex]['amount'] = round($amount, 2);
        $rows[$anchorIndex]['note'] = __('Full early settlement');
        $rows[$anchorIndex]['days_until_due'] = null;

        $dropIndexes = array_fill_keys(array_slice($pendingIndexes, 1), true);
        if ($dropIndexes !== []) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row, int $index): bool => ! isset($dropIndexes[$index]),
                ARRAY_FILTER_USE_BOTH,
            ));
        }

        return $rows;
    }

    /**
     * Skip marks the earliest pending cycles as skipped and keeps later cycles on the same dates.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function afterSkipSettlement(array $state, int $covered): array
    {
        $rows = is_array($state['schedule_rows'] ?? null) ? $state['schedule_rows'] : [];
        $pendingIndexes = $this->pendingIndexes($rows);
        $covered = min(max(0, $covered), count($pendingIndexes));

        if ($covered <= 0) {
            return $this->syncScheduleAfterSettlement($state, $rows);
        }

        foreach (array_slice($pendingIndexes, 0, $covered) as $index) {
            $rows[$index]['kind'] = 'skipped';
            $rows[$index]['amount'] = 0.0;
            $rows[$index]['note'] = __('Skipped by partial early settlement');
            $rows[$index]['days_until_due'] = null;
        }

        return $this->syncScheduleAfterSettlement($state, $rows);
    }

    /**
     * Redistribute pending installment amounts for remaining maturity. Drop trailing
     * pending rows that are no longer needed (including a final installment that
     * would be zero) so total/pending counts and projected maturity stay correct.
     *
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function syncScheduleAfterSettlement(array $state, array $rows): array
    {
        $remaining = (float) $state['remaining_maturity'];
        $min = (float) $state['min_installment'];
        $needed = $this->monthsForRemaining($remaining, $min);

        $pendingIndexes = $this->pendingIndexes($rows);
        $pendingCount = count($pendingIndexes);

        if ($needed < $pendingCount) {
            $dropCount = $pendingCount - max(0, $needed);
            $dropIndexes = array_fill_keys(array_slice($pendingIndexes, -$dropCount), true);
            $rows = array_values(array_filter(
                $rows,
                fn (array $row, int $index): bool => ! isset($dropIndexes[$index]),
                ARRAY_FILTER_USE_BOTH,
            ));
            $pendingIndexes = $this->pendingIndexes($rows);
            $pendingCount = count($pendingIndexes);
        }

        foreach ($pendingIndexes as $i => $index) {
            // Partial settlements adjust the next applicable (first pending) cycle;
            // later cycles stay at the min installment (not the schedule tail).
            $rows[$index]['amount'] = $this->pendingInstallmentAmount($i + 1, $pendingCount, $min, $remaining);
            $due = (string) ($rows[$index]['due_date'] ?? '');
            if ($due !== '') {
                $rows[$index]['days_until_due'] = (int) Carbon::today()->startOfDay()
                    ->diffInDays(Carbon::parse($due)->startOfDay(), false);
            }
        }

        // Safety: if the next installment still computes to ~0, remove it and recompute.
        if ($pendingCount > 1) {
            $firstIndex = $pendingIndexes[0];
            if ((float) ($rows[$firstIndex]['amount'] ?? 0) <= 0.00001) {
                unset($rows[$firstIndex]);
                $rows = array_values($rows);

                return $this->syncScheduleAfterSettlement($state, $rows);
            }
        }

        $firstPending = $pendingIndexes === [] ? null : $rows[$pendingIndexes[0]];
        $nextDue = is_array($firstPending) && filled($firstPending['due_date'] ?? null)
            ? Carbon::parse((string) $firstPending['due_date'])->startOfDay()
            : null;
        $lastPending = $pendingIndexes === [] ? null : $rows[$pendingIndexes[array_key_last($pendingIndexes)]];

        $state['schedule_rows'] = $rows;
        $state['remaining_months'] = $pendingCount;
        $state['next_due_date'] = $nextDue?->toDateString();
        $state['expected_maturity_date'] = is_array($lastPending) && filled($lastPending['due_date'] ?? null)
            ? (string) $lastPending['due_date']
            : ($nextDue === null ? null : $this->expectedMaturityDate($nextDue, $remaining, $min));

        return $this->withDerived($state);
    }

    /**
     * Amount for a pending installment when redistributing remaining maturity after
     * partial settlement. The next applicable cycle (position 1) absorbs any shortfall;
     * later cycles keep the minimum installment.
     */
    private function pendingInstallmentAmount(
        int $position,
        int $pendingCount,
        float $minInstallment,
        float $remainingMaturity,
    ): float {
        if ($pendingCount <= 0 || $position < 1 || $position > $pendingCount) {
            return 0.0;
        }

        if ($position > 1) {
            return round($minInstallment, 2);
        }

        $laterTotal = round($minInstallment * ($pendingCount - 1), 2);

        return round(max(0.0, $remainingMaturity - $laterTotal), 2);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    private function pendingIndexes(array $rows): array
    {
        $indexes = [];

        foreach ($rows as $index => $row) {
            if (($row['kind'] ?? '') === 'pending') {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function markPaid(array $state): array
    {
        $fundBefore = (float) $state['fund_balance'];
        $state['status'] = self::STATUS_PAID;
        $state['outstanding_fund_portion'] = 0.0;
        $state['remaining_maturity'] = 0.0;
        $state['remaining_months'] = 0;
        $state['next_due_date'] = null;
        $state['expected_maturity_date'] = null;
        $state['fund_balance'] = round(max((float) $state['fund_balance'], (float) $state['top_up']), 2);
        $state['schedule_rows'] = $this->markOpenRows(
            is_array($state['schedule_rows'] ?? null) ? $state['schedule_rows'] : [],
            'paid',
            __('Paid at normal maturity'),
        );
        $state['history'][] = $this->historyEvent(
            'paid',
            __('Normal maturity (Paid)'),
            0.0,
            $fundBefore,
            (float) $state['fund_balance'],
            0.0,
            0.0,
            (float) $state['total_repaid'],
        );

        return $this->withDerived($state);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function markOpenRows(array $rows, string $kind, string $note): array
    {
        return array_map(function (array $row) use ($kind, $note): array {
            if (($row['kind'] ?? '') === 'pending') {
                $row['kind'] = $kind;
                $row['amount'] = $kind === 'cancelled' ? 0.0 : (float) ($row['amount'] ?? 0);
                $row['note'] = $note;
            }

            return $row;
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPendingSchedule(
        Carbon $firstDue,
        float $remaining,
        float $minInstallment,
        int $startNumber = 1,
    ): array {
        $count = $this->monthsForRemaining($remaining, $minInstallment);

        if ($count <= 0 || $remaining <= 0.00001) {
            return [];
        }

        $rows = [];
        $cursor = $firstDue->copy()->startOfDay();
        $number = max(1, $startNumber);

        for ($i = 1; $i <= $count; $i++) {
            $amount = Loan::scheduleInstallmentAmount($i, $count, $minInstallment, $remaining);
            $rows[] = $this->scheduleRow('pending', $cursor, $number, $amount);
            $number++;
            $cursor = $cursor->copy()->addMonthNoOverflow();
        }

        return $rows;
    }

    /**
     * Seed simulator schedule from the estimate (grace / contribution prefix + EMIs).
     *
     * @param  array<string, mixed>  $calc
     * @return list<array<string, mixed>>
     */
    private function seedScheduleFromEstimate(
        array $calc,
        Carbon $fallbackFirstDue,
        float $maturity,
        float $minInstallment,
    ): array {
        $sourceRows = is_array($calc['schedule']['rows'] ?? null) ? $calc['schedule']['rows'] : [];
        $prefix = [];
        $installments = [];

        foreach ($sourceRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $kind = (string) ($row['kind'] ?? '');

            if (in_array($kind, ['grace', 'contribution_due', 'contribution_paid'], true)) {
                $prefix[] = $this->importScheduleRow($row, $kind);

                continue;
            }

            if (in_array($kind, ['emi', 'pending', 'rolled_up', 'skipped', 'dropped'], true)) {
                $mapped = match ($kind) {
                    'emi' => 'pending',
                    'rolled_up' => 'paid',
                    default => $kind,
                };
                $installments[] = $this->importScheduleRow($row, $mapped);
            }
        }

        if ($installments === []) {
            return [
                ...$prefix,
                ...$this->buildPendingSchedule($fallbackFirstDue, $maturity, $minInstallment),
            ];
        }

        return [...$prefix, ...$installments];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function importScheduleRow(array $row, string $kind): array
    {
        $dueDate = (string) ($row['due_date'] ?? '');
        $due = $dueDate !== '' ? Carbon::parse($dueDate)->startOfDay() : Carbon::today()->startOfDay();
        $locale = app()->getLocale();
        $today = Carbon::today()->startOfDay();
        $amount = $kind === 'skipped' || $kind === 'dropped'
            ? 0.0
            : round((float) ($row['amount'] ?? 0), 2);

        return [
            'kind' => $kind,
            'number' => isset($row['number']) && $row['number'] !== null ? (int) $row['number'] : null,
            'cycle_label' => (string) ($row['cycle_label'] ?? $due->copy()->locale($locale)->translatedFormat('F Y')),
            'due_date' => $due->toDateString(),
            'due_label' => (string) ($row['due_label'] ?? $due->copy()->locale($locale)->translatedFormat('j M Y')),
            'amount' => $amount,
            'note' => match ($kind) {
                'grace' => __('Grace'),
                'contribution_due' => __('Contribution due'),
                'contribution_paid' => __('Contribution paid'),
                'paid' => __('Rolled up'),
                'skipped' => __('Skipped'),
                'dropped' => __('Removed by roll-up'),
                default => null,
            },
            'days_until_due' => $kind === 'pending' ? (int) $today->diffInDays($due, false) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyEvent(
        string $type,
        string $label,
        float $amount,
        float $fundBefore,
        float $fundAfter,
        float $outstandingFundPortion,
        float $remainingMaturity,
        float $totalRepaid,
        ?float $outstandingBefore = null,
        ?float $remainingBefore = null,
        ?string $at = null,
    ): array {
        $atDate = filled($at)
            ? Carbon::parse($at)->startOfDay()
            : BusinessDay::today()->startOfDay();
        $locale = app()->getLocale();

        return [
            'type' => $type,
            'label' => $label,
            'amount' => round($amount, 2),
            'at' => $atDate->toDateString(),
            'at_label' => $atDate->copy()->locale($locale)->translatedFormat('j M Y'),
            'fund_before' => round($fundBefore, 2),
            'fund_balance' => round($fundAfter, 2),
            'fund_delta' => round($fundAfter - $fundBefore, 2),
            'outstanding_fund_portion' => round($outstandingFundPortion, 2),
            'outstanding_before' => round($outstandingBefore ?? $outstandingFundPortion, 2),
            'remaining_maturity' => round($remainingMaturity, 2),
            'remaining_before' => round($remainingBefore ?? $remainingMaturity, 2),
            'total_repaid' => round($totalRepaid, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function nextPendingDueDate(array $state): ?string
    {
        foreach (is_array($state['schedule_rows'] ?? null) ? $state['schedule_rows'] : [] as $row) {
            if (! is_array($row) || ($row['kind'] ?? '') !== 'pending') {
                continue;
            }

            $due = (string) ($row['due_date'] ?? '');

            return $due !== '' ? $due : null;
        }

        $next = (string) ($state['next_due_date'] ?? '');

        return $next !== '' ? $next : null;
    }

    /**
     * @return array{kind: string, number: int|null, cycle_label: string, due_date: string, due_label: string, amount: float, note: string|null, days_until_due: int|null}
     */
    private function scheduleRow(
        string $kind,
        Carbon $due,
        ?int $number = null,
        float $amount = 0.0,
        ?string $note = null,
    ): array {
        $locale = app()->getLocale();
        $today = Carbon::today()->startOfDay();

        return [
            'kind' => $kind,
            'number' => $number,
            'cycle_label' => $due->copy()->locale($locale)->translatedFormat('F Y'),
            'due_date' => $due->toDateString(),
            'due_label' => $due->copy()->locale($locale)->translatedFormat('j M Y'),
            'amount' => round($amount, 2),
            'note' => $note,
            'days_until_due' => $kind === 'pending' ? (int) $today->diffInDays($due, false) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function assertActive(array $state, string $message): void
    {
        if ($state['status'] !== self::STATUS_ACTIVE) {
            throw new InvalidArgumentException($message);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalize(array $state): array
    {
        foreach ([
            'loan_amount',
            'member_portion',
            'master_portion',
            'top_up',
            'maturity_amount',
            'min_installment',
            'eligibility_amt',
            'eligibility_base',
            'pre_loan_fund',
            'fund_balance',
            'cash_balance',
            'excess_to_cash',
            'outstanding_fund_portion',
            'total_repaid',
            'remaining_maturity',
            'full_settlement_amount',
        ] as $key) {
            $state[$key] = round((float) ($state[$key] ?? 0), 2);
        }

        $state['remaining_months'] = (int) ($state['remaining_months'] ?? 0);
        $state['status'] = (string) ($state['status'] ?? self::STATUS_ACTIVE);
        $state['history'] = is_array($state['history'] ?? null) ? $state['history'] : [];
        $state['schedule_rows'] = is_array($state['schedule_rows'] ?? null) ? $state['schedule_rows'] : [];
        $state['eligible_for_new_loan'] = (bool) ($state['eligible_for_new_loan'] ?? false);
        $state['cash_out_excess_fund'] = (bool) ($state['cash_out_excess_fund'] ?? false);

        return $state;
    }

    private function monthsForRemaining(float $remaining, float $minInstallment): int
    {
        if ($remaining <= 0.00001 || $minInstallment <= 0.00001) {
            return 0;
        }

        return (int) ceil($remaining / $minInstallment);
    }

    private function expectedMaturityDate(Carbon $nextDue, float $remaining, float $minInstallment): ?string
    {
        $months = $this->monthsForRemaining($remaining, $minInstallment);

        if ($months <= 0) {
            return null;
        }

        return $nextDue->copy()->addMonthsNoOverflow(max(0, $months - 1))->toDateString();
    }

    private function resolvedStartDate(?string $startDate): Carbon
    {
        if (is_string($startDate) && $startDate !== '') {
            try {
                return Carbon::parse($startDate)->startOfDay();
            } catch (\Throwable) {
            }
        }

        return Carbon::today()->startOfDay();
    }
}
