<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use App\Support\LoanCalculatorCurrentLoanSettlement;
use App\Support\LoanCalculatorOutstandingThresholds;
use App\Support\LoanExcessFundSettlementOption;
use App\Support\LoanFundingStrategy;
use App\Support\LoanRepaymentWindowPolicy;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

final class MemberLoanCalculatorService
{
    public function __construct(
        private readonly LoanRepaymentWindowPolicy $repaymentWindows,
        private readonly ContributionCycleService $cycles,
    ) {}

    /**
     * @return list<array{
     *     tier: LoanTier,
     *     min_installment: float,
     *     installments: int,
     *     member_portion: float,
     *     master_portion: float,
     *     settlement_amt: float,
     *     total_repay: float,
     *     eligibility_amt: float,
     *     eligibility_base: float,
     *     excess_fund: float,
     *     early_settlement_amount: float,
     *     installments_covered: int,
     *     remaining_payment_months: int|null,
     *     duration_months: int,
     *     projected_fund: float,
     *     projection: array<string, mixed>,
     *     schedule: array<string, mixed>
     * }>
     */
    public function calculationsForAmount(
        float $loanAmount,
        Member $member,
        ?string $fundingStrategy = null,
        ?string $excessFundSettlementOption = null,
        int $graceCycles = 0,
        ?string $startDate = null,
        int|float|string|null $projectedContributionAmount = null,
        ?string $currentLoanSettlement = null,
        ?LoanCalculatorOutstandingThresholds $outstandingThresholds = null,
    ): array {
        if ($loanAmount <= 0) {
            return [];
        }

        if ($this->estimateBlockReason(
            $loanAmount,
            $member,
            $fundingStrategy,
            $startDate,
            $projectedContributionAmount,
            $currentLoanSettlement,
            $outstandingThresholds,
        ) !== null) {
            return [];
        }

        $projection = $this->fundProjection(
            $member,
            $startDate,
            $projectedContributionAmount,
            $currentLoanSettlement,
            $loanAmount,
            $outstandingThresholds,
        );
        $fundBalance = $projection['projected_fund'];
        $settlementPct = LoanSettings::settlementThreshold();
        $eligibilityPct = LoanSettings::eligibilityThreshold();
        $strategy = LoanFundingStrategy::normalize($fundingStrategy);
        $settlementChoice = LoanExcessFundSettlementOption::normalize($excessFundSettlementOption);
        $grace = LoanSettings::clampGraceCycles($graceCycles);
        $results = [];

        foreach ($this->activeTiers() as $tier) {
            if ($loanAmount < (float) $tier->min_amount || $loanAmount > (float) $tier->max_amount) {
                continue;
            }

            $portions = LoanSettings::resolveFundingPortions($loanAmount, $fundBalance, $strategy);
            $memberPortion = $portions['member_portion'];
            $masterPortion = $portions['master_portion'];
            $minInstallment = (float) $tier->min_monthly_installment;
            $installments = Loan::computeInstallmentsCountFromPortions(
                $loanAmount,
                $memberPortion,
                $minInstallment,
                $settlementPct,
            );
            $settlementAmt = round($loanAmount * $settlementPct, 2);
            $totalToRepay = round($masterPortion + $settlementAmt, 2);
            $eligibilityBase = (float) $tier->max_amount;
            $eligibilityAmt = round($eligibilityBase * $eligibilityPct, 2);
            $excessFund = LoanSettings::excessFundCashOutAmount($loanAmount, $fundBalance, $strategy);

            $earlySettlementAmount = 0.0;
            $installmentsCovered = 0;
            $remainingPaymentMonths = null;
            $settlementMode = null;

            if (
                $strategy === LoanFundingStrategy::SPLIT_WITH_EARLY_SETTLEMENT
                && LoanExcessFundSettlementOption::appliesAsEarlySettlement($settlementChoice)
                && $excessFund > 0.00001
                && $minInstallment > 0.00001
            ) {
                $earlySettlementAmount = $excessFund;
                $installmentsCovered = (int) floor($excessFund / $minInstallment);
                $settlementMode = LoanExcessFundSettlementOption::toSettlementOption($settlementChoice);

                if ($installmentsCovered > 0) {
                    $remainingPaymentMonths = match ($settlementChoice) {
                        LoanExcessFundSettlementOption::ROLL_UP => max(0, $installments - (2 * $installmentsCovered)),
                        LoanExcessFundSettlementOption::SKIP_FUTURE => max(0, $installments - $installmentsCovered),
                        default => null,
                    };
                }
            }

            $durationMonths = $installments;
            if ($settlementMode === 'roll_up' && $remainingPaymentMonths !== null) {
                $durationMonths = $remainingPaymentMonths + min($installmentsCovered, $installments);
            }

            $results[] = [
                'tier' => $tier,
                'min_installment' => $minInstallment,
                'installments' => $installments,
                'member_portion' => $memberPortion,
                'master_portion' => $masterPortion,
                'settlement_amt' => $settlementAmt,
                'total_repay' => $totalToRepay,
                'eligibility_amt' => $eligibilityAmt,
                'eligibility_base' => $eligibilityBase,
                'excess_fund' => $excessFund,
                'early_settlement_amount' => $earlySettlementAmount,
                'early_settlement_mode' => $settlementMode,
                'installments_covered' => $installmentsCovered,
                'remaining_payment_months' => $remainingPaymentMonths,
                'duration_months' => $durationMonths,
                'projected_fund' => $fundBalance,
                'projection' => $projection,
                'schedule' => $this->estimatedSchedule(
                    $installments,
                    $minInstallment,
                    $totalToRepay,
                    $grace,
                    $settlementMode,
                    $installmentsCovered,
                    $projection,
                    $earlySettlementAmount,
                ),
            ];
        }

        return $results;
    }

    /**
     * Reason the calculator must not estimate or simulate this amount, or null when allowed.
     */
    public function estimateBlockReason(
        float $loanAmount,
        Member $member,
        ?string $fundingStrategy = null,
        ?string $startDate = null,
        int|float|string|null $projectedContributionAmount = null,
        ?string $currentLoanSettlement = null,
        ?LoanCalculatorOutstandingThresholds $outstandingThresholds = null,
    ): ?string {
        if ($loanAmount <= 0.00001) {
            return null;
        }

        $projection = $this->fundProjection(
            $member,
            $startDate,
            $projectedContributionAmount,
            $currentLoanSettlement,
            $loanAmount,
            $outstandingThresholds,
        );
        $projectedFund = round((float) $projection['projected_fund'], 2);

        if ($projectedFund < -0.00001) {
            return __('Projected fund at start must not be negative.');
        }

        $portions = LoanSettings::resolveFundingPortions($loanAmount, $projectedFund, $fundingStrategy);
        $memberPortion = round((float) $portions['member_portion'], 2);

        if ($memberPortion > $projectedFund + 0.00001) {
            return __('Your member portion (:portion) exceeds the projected fund at start (:fund).', [
                'portion' => number_format($memberPortion, 2),
                'fund' => number_format($projectedFund, 2),
            ]);
        }

        return null;
    }

    /**
     * @param  array{
     *     current_fund: float,
     *     contribution_amount: float,
     *     cycles_added: int,
     *     loan_repayment_cycles: int,
     *     loan_repayment_amount: float,
     *     loan_repayment_installment: float|null,
     *     loan_settlement_mode: string,
     *     projected_fund: float,
     *     cash_needed: float,
     *     start_cycle_month: int,
     *     start_cycle_year: int,
     *     start_cycle_label: string,
     *     start_cycle_paid: bool,
     *     requested_start_date?: string,
     *     effective_start_date?: string,
     *     start_date_adjusted?: bool,
     *     start_date_adjusted_reasons?: list<string>,
     *     settlement_required?: float,
     *     eligibility_required?: float,
     *     cannot_meet_thresholds?: bool,
     *     include_settlement_threshold?: bool,
     *     include_eligibility_threshold?: bool
     * }  $projection
     * @return array{
     *     grace_cycles: int,
     *     assumed_cycle_label: string,
     *     current_cycle_contribution: string,
     *     current_cycle_contribution_label: string,
     *     first_due_date: string|null,
     *     last_due_date: string|null,
     *     first_due_label: string|null,
     *     last_due_label: string|null,
     *     payable_count: int,
     *     rows: list<array{
     *         kind: string,
     *         number: int|null,
     *         cycle_month: int,
     *         cycle_year: int,
     *         cycle_label: string,
     *         due_date: string|null,
     *         due_label: string|null,
     *         amount: float,
     *         is_final: bool
     *     }>
     * }
     */
    public function estimatedSchedule(
        int $installments,
        float $minInstallment,
        float $totalToRepay,
        int $graceCycles,
        ?string $settlementMode,
        int $installmentsCovered,
        array $projection,
        float $earlySettlementAmount = 0.0,
    ): array {
        $grace = LoanSettings::clampGraceCycles($graceCycles);
        $period = Carbon::create(
            (int) $projection['start_cycle_year'],
            (int) $projection['start_cycle_month'],
            1,
        )->startOfMonth();
        $startPaid = (bool) $projection['start_cycle_paid'];
        $prefixRows = [];

        if ($startPaid) {
            $prefixRows[] = $this->scheduleRow('contribution_paid', $period);
            $period = $period->copy()->addMonthNoOverflow();
        } elseif ($grace === 0) {
            $prefixRows[] = $this->scheduleRow('contribution_due', $period);
            $period = $period->copy()->addMonthNoOverflow();
        }

        for ($g = 0; $g < $grace; $g++) {
            $prefixRows[] = $this->scheduleRow('grace', $period);
            $period = $period->copy()->addMonthNoOverflow();
        }

        $emiRows = [];

        if ($installments > 0 && $totalToRepay > 0.01) {
            for ($i = 1; $i <= $installments; $i++) {
                $emiRows[] = $this->scheduleRow(
                    'emi',
                    $period,
                    $i,
                    Loan::scheduleInstallmentAmount($i, $installments, $minInstallment, $totalToRepay),
                    $i === $installments,
                );
                $period = $period->copy()->addMonthNoOverflow();
            }
        }

        $emiRows = $this->applyEarlySettlementToEmiRows(
            $emiRows,
            $settlementMode,
            $installmentsCovered,
            $minInstallment,
            $earlySettlementAmount,
        );
        $rows = [...$prefixRows, ...$emiRows];
        $payable = array_values(array_filter($rows, fn (array $row): bool => $row['kind'] === 'emi'));
        $firstPayable = $payable[0] ?? null;
        $lastPayable = $payable === [] ? null : $payable[array_key_last($payable)];
        $contributionStatus = $this->startCycleContributionStatus($startPaid, $grace);

        return [
            'grace_cycles' => $grace,
            'assumed_cycle_label' => $projection['start_cycle_label'],
            'current_cycle_contribution' => $contributionStatus,
            'current_cycle_contribution_label' => $this->startCycleContributionLabel($contributionStatus),
            'first_due_date' => $firstPayable['due_date'] ?? null,
            'last_due_date' => $lastPayable['due_date'] ?? null,
            'first_due_label' => $firstPayable['due_label'] ?? null,
            'last_due_label' => $lastPayable['due_label'] ?? null,
            'payable_count' => count($payable),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     current_fund: float,
     *     contribution_amount: float,
     *     cycles_added: int,
     *     loan_repayment_cycles: int,
     *     loan_repayment_amount: float,
     *     loan_repayment_installment: float|null,
     *     loan_settlement_mode: string,
     *     projected_fund: float,
     *     cash_needed: float,
     *     start_cycle_month: int,
     *     start_cycle_year: int,
     *     start_cycle_label: string,
     *     start_cycle_paid: bool,
     *     requested_start_date: string,
     *     effective_start_date: string,
     *     start_date_adjusted: bool,
     *     start_date_adjusted_reasons: list<string>,
     *     settlement_required: float,
     *     eligibility_required: float,
     *     cannot_meet_thresholds: bool,
     *     include_settlement_threshold: bool,
     *     include_eligibility_threshold: bool
     * }
     */
    public function fundProjection(
        Member $member,
        ?string $startDate = null,
        int|float|string|null $projectedContributionAmount = null,
        ?string $currentLoanSettlement = null,
        float $newLoanAmount = 0.0,
        ?LoanCalculatorOutstandingThresholds $outstandingThresholds = null,
    ): array {
        $thresholds = $outstandingThresholds ?? LoanCalculatorOutstandingThresholds::none();
        $requestedStart = $this->resolvedStartDate($startDate);
        $horizon = $this->resolveOutstandingLoanHorizon(
            $member,
            $requestedStart,
            $projectedContributionAmount,
            $currentLoanSettlement,
            $newLoanAmount,
            $thresholds,
        );
        $start = $horizon['start'];
        $currentFund = round($member->getFundBalance(), 2);
        $contributionAmount = $this->resolvedContributionAmount($member, $projectedContributionAmount);
        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        [$startMonth, $startYear] = $this->cycles->periodContaining($start);
        $from = Carbon::create($openYear, $openMonth, 1)->startOfMonth();
        $to = Carbon::create($startYear, $startMonth, 1)->startOfMonth();
        $currentPosted = Contribution::activePeriodExists((int) $member->id, $openMonth, $openYear);
        $windowCycles = $this->projectedContributionCycles($from, $to, $currentPosted);
        $remainingPayments = $this->remainingActiveLoanInstallmentAmounts($member);
        $settlementMode = LoanCalculatorCurrentLoanSettlement::normalize($currentLoanSettlement);

        if ($remainingPayments !== [] && LoanCalculatorCurrentLoanSettlement::isFullEarlySettlement($settlementMode)) {
            $loanRepaymentAmount = $this->fullEarlySettlementRestoreAmount($member, $currentFund);
            $repaymentCycles = 0;
            $contributionCycles = $windowCycles;
            $appliedPayments = [];
        } elseif ($remainingPayments !== [] && LoanCalculatorCurrentLoanSettlement::isPartialToMaturity($settlementMode)) {
            $loanRepaymentAmount = round(array_sum($remainingPayments), 2);
            $repaymentCycles = 0;
            $contributionCycles = $windowCycles;
            $appliedPayments = [];
        } else {
            $repaymentCycles = min($windowCycles, count($remainingPayments));
            $appliedPayments = array_slice($remainingPayments, 0, $repaymentCycles);
            $loanRepaymentAmount = round(array_sum($appliedPayments), 2);
            $contributionCycles = $windowCycles - $repaymentCycles;
        }

        $projectedFund = round($currentFund + $loanRepaymentAmount + ($contributionCycles * $contributionAmount), 2);
        $cashNeeded = round(max(0.0, $loanRepaymentAmount) + ($contributionCycles * $contributionAmount), 2);
        $startPosted = Contribution::activePeriodExists((int) $member->id, $startMonth, $startYear);
        $startCyclePaid = $startPosted
            || ($contributionCycles > 0 && $to->greaterThan($from) && $contributionAmount > 0.00001);

        return [
            'current_fund' => $currentFund,
            'contribution_amount' => $contributionAmount,
            'cycles_added' => $contributionCycles,
            'loan_repayment_cycles' => $repaymentCycles,
            'loan_repayment_amount' => $loanRepaymentAmount,
            'loan_repayment_installment' => $this->uniformInstallmentAmount($appliedPayments),
            'loan_settlement_mode' => $settlementMode,
            'projected_fund' => $projectedFund,
            'cash_needed' => $cashNeeded,
            'start_cycle_month' => $startMonth,
            'start_cycle_year' => $startYear,
            'start_cycle_label' => $this->cycles->periodLabel($startMonth, $startYear),
            'start_cycle_paid' => $startCyclePaid,
            'requested_start_date' => $requestedStart->toDateString(),
            'effective_start_date' => $start->toDateString(),
            'start_date_adjusted' => $horizon['adjusted'],
            'start_date_adjusted_reasons' => $horizon['reasons'],
            'settlement_required' => $horizon['settlement_required'],
            'eligibility_required' => $horizon['eligibility_required'],
            'cannot_meet_thresholds' => $horizon['cannot_meet'],
            'include_settlement_threshold' => $thresholds->settlement,
            'include_eligibility_threshold' => $thresholds->eligibility,
        ];
    }

    /**
     * @return Collection<int, LoanTier>
     */
    public function activeTiers(): Collection
    {
        return LoanTier::query()
            ->where('is_active', true)
            ->orderBy('min_amount')
            ->get();
    }

    public function settlementThresholdPercent(): float
    {
        return LoanSettings::settlementThreshold();
    }

    public function eligibilityThresholdPercent(): float
    {
        return LoanSettings::eligibilityThreshold();
    }

    public function cycleLabelForDate(?string $startDate): string
    {
        [$month, $year] = $this->cycles->periodContaining($this->resolvedStartDate($startDate));

        return $this->cycles->periodLabel($month, $year);
    }

    private function startCycleContributionStatus(bool $startPaid, int $graceCycles): string
    {
        if ($startPaid) {
            return 'already_paid';
        }

        if ($graceCycles > 0) {
            return 'exempt_grace';
        }

        return 'due';
    }

    private function startCycleContributionLabel(string $status): string
    {
        return match ($status) {
            'already_paid' => __('This cycle’s contribution is already paid, so grace starts on the next unpaid cycle.'),
            'exempt_grace' => __('This cycle’s contribution is skipped because this cycle is grace.'),
            default => __('This cycle’s contribution is still due.'),
        };
    }

    private function projectedContributionCycles(Carbon $from, Carbon $to, bool $currentPosted): int
    {
        if ($to->lessThan($from)) {
            return 0;
        }

        if ($from->equalTo($to)) {
            return 0;
        }

        $cycles = 0;
        $cursor = $from->copy();

        for ($i = 0; $i < 120 && $cursor->lessThanOrEqualTo($to); $i++) {
            if (! ($cursor->equalTo($from) && $currentPosted)) {
                $cycles++;
            }

            $cursor->addMonthNoOverflow();
        }

        return $cycles;
    }

    /**
     * @return array{
     *     start: Carbon,
     *     adjusted: bool,
     *     reasons: list<string>,
     *     settlement_required: float,
     *     eligibility_required: float,
     *     cannot_meet: bool
     * }
     */
    public function resolveOutstandingLoanHorizon(
        Member $member,
        Carbon $requestedStart,
        int|float|string|null $projectedContributionAmount,
        ?string $currentLoanSettlement,
        float $newLoanAmount = 0.0,
        ?LoanCalculatorOutstandingThresholds $outstandingThresholds = null,
    ): array {
        $thresholds = $outstandingThresholds ?? LoanCalculatorOutstandingThresholds::none();
        $remainingPayments = $this->remainingActiveLoanInstallmentAmounts($member);
        $floors = $this->outstandingLoanFundFloors($member, $newLoanAmount, $thresholds);
        $empty = [
            'start' => $requestedStart->copy()->startOfDay(),
            'adjusted' => false,
            'reasons' => [],
            'settlement_required' => $floors['settlement'],
            'eligibility_required' => $floors['eligibility'],
            'cannot_meet' => false,
        ];

        if ($remainingPayments === [] || ! $thresholds->any()) {
            return $empty;
        }

        $contributionAmount = $this->resolvedContributionAmount($member, $projectedContributionAmount);
        $settlementMode = LoanCalculatorCurrentLoanSettlement::normalize($currentLoanSettlement);
        $currentFund = round($member->getFundBalance(), 2);
        $emiCount = count($remainingPayments);
        $emiSum = round(array_sum($remainingPayments), 2);

        if (LoanCalculatorCurrentLoanSettlement::isFullEarlySettlement($settlementMode)) {
            $fundAfterRepayments = round($currentFund + $this->fullEarlySettlementRestoreAmount($member, $currentFund), 2);
            $repaymentCyclesNeeded = 0;
        } elseif (LoanCalculatorCurrentLoanSettlement::isPartialToMaturity($settlementMode)) {
            $fundAfterRepayments = round($currentFund + $emiSum, 2);
            $repaymentCyclesNeeded = 0;
        } else {
            $fundAfterRepayments = round($currentFund + $emiSum, 2);
            $repaymentCyclesNeeded = $emiCount;
        }

        $extraToSettle = $this->extraContributionCyclesToReach(
            $fundAfterRepayments,
            $floors['settlement'],
            $contributionAmount,
        );
        $extraToEligible = $this->extraContributionCyclesToReach(
            $fundAfterRepayments,
            max($floors['settlement'], $floors['eligibility']),
            $contributionAmount,
        );

        if ($extraToSettle === PHP_INT_MAX || $extraToEligible === PHP_INT_MAX) {
            $empty['cannot_meet'] = true;

            $minCycles = $repaymentCyclesNeeded;
            if ($minCycles <= 0) {
                return $empty;
            }

            $start = $this->earliestDateWithWindowCycles($member, $requestedStart, $minCycles);
            $empty['start'] = $start;
            $empty['adjusted'] = $start->toDateString() !== $requestedStart->toDateString();
            $reasons = [];

            if ($empty['adjusted'] && $thresholds->settlement) {
                $reasons[] = 'settlement';
            }

            if ($empty['adjusted'] && $thresholds->eligibility) {
                $reasons[] = 'eligibility';
            }

            $empty['reasons'] = $reasons;

            return $empty;
        }

        $minCyclesToSettle = $repaymentCyclesNeeded + $extraToSettle;
        $minCyclesToEligible = $repaymentCyclesNeeded + $extraToEligible;
        $requiredCycles = max($minCyclesToSettle, $minCyclesToEligible);
        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        $from = Carbon::create($openYear, $openMonth, 1)->startOfMonth();
        [$requestedMonth, $requestedYear] = $this->cycles->periodContaining($requestedStart);
        $requestedTo = Carbon::create($requestedYear, $requestedMonth, 1)->startOfMonth();
        $currentPosted = Contribution::activePeriodExists((int) $member->id, $openMonth, $openYear);
        $requestedWindow = $this->projectedContributionCycles($from, $requestedTo, $currentPosted);

        $reasons = $this->outstandingHorizonReasons(
            $thresholds,
            $requestedWindow,
            $minCyclesToSettle,
            $minCyclesToEligible,
        );

        $start = $requiredCycles > $requestedWindow
            ? $this->earliestDateWithWindowCycles($member, $requestedStart, $requiredCycles)
            : $requestedStart->copy()->startOfDay();

        return [
            'start' => $start,
            'adjusted' => $start->toDateString() !== $requestedStart->toDateString(),
            'reasons' => $start->toDateString() !== $requestedStart->toDateString() ? $reasons : [],
            'settlement_required' => $floors['settlement'],
            'eligibility_required' => $floors['eligibility'],
            'cannot_meet' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function outstandingHorizonReasons(
        LoanCalculatorOutstandingThresholds $thresholds,
        int $requestedWindow,
        int $minCyclesToSettle,
        int $minCyclesToEligible,
    ): array {
        $reasons = [];

        if ($thresholds->settlement && $requestedWindow < $minCyclesToSettle) {
            $reasons[] = 'settlement';
        }

        if ($thresholds->eligibility && $requestedWindow < $minCyclesToEligible) {
            $reasons[] = 'eligibility';
        }

        return $reasons;
    }

    /**
     * @return array{settlement: float, eligibility: float}
     */
    private function outstandingLoanFundFloors(
        Member $member,
        float $newLoanAmount,
        LoanCalculatorOutstandingThresholds $thresholds,
    ): array {

        $loans = Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', ['active', 'transferred'])
            ->with('loanTier')
            ->orderBy('id')
            ->get();

        $settlement = 0.0;
        $eligibility = 0.0;

        foreach ($loans as $loan) {
            $approved = round((float) ($loan->amount_approved ?: $loan->amount), 2);
            $settlementPct = $loan->settlement_threshold !== null
                ? max(0.0, (float) $loan->settlement_threshold)
                : LoanSettings::settlementThreshold();
            $settlement = max($settlement, round($approved * $settlementPct, 2));
            $eligibility = max($eligibility, $loan->eligibilityThresholdAmount());
        }

        if ($newLoanAmount > 0.00001) {
            $tier = LoanTier::forAmount($newLoanAmount);

            if ($tier !== null) {
                $eligibility = max(
                    $eligibility,
                    round((float) $tier->max_amount * LoanSettings::eligibilityThreshold(), 2),
                );
            }
        }

        return [
            'settlement' => $thresholds->settlement ? $settlement : 0.0,
            'eligibility' => $thresholds->eligibility ? $eligibility : 0.0,
        ];
    }

    private function extraContributionCyclesToReach(float $fund, float $target, float $contribution): int
    {
        $shortfall = round($target - $fund, 2);

        if ($shortfall <= 0.00001) {
            return 0;
        }

        if ($contribution <= 0.00001) {
            return PHP_INT_MAX;
        }

        return (int) ceil($shortfall / $contribution - 0.000000001);
    }

    private function earliestDateWithWindowCycles(Member $member, Carbon $notBefore, int $requiredCycles): Carbon
    {
        $candidate = $notBefore->copy()->startOfDay();

        if ($requiredCycles <= 0) {
            return $candidate;
        }

        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        $from = Carbon::create($openYear, $openMonth, 1)->startOfMonth();
        $currentPosted = Contribution::activePeriodExists(
            (int) $member->id,
            $openMonth,
            $openYear,
        );

        for ($i = 0; $i < 120; $i++) {
            [$startMonth, $startYear] = $this->cycles->periodContaining($candidate);
            $to = Carbon::create($startYear, $startMonth, 1)->startOfMonth();

            if ($this->projectedContributionCycles($from, $to, $currentPosted) >= $requiredCycles) {
                return $candidate;
            }

            $candidate = $candidate->copy()->addMonthNoOverflow();
        }

        return $candidate;
    }

    /**
     * Unpaid EMIs on the member's in-repayment loan(s), oldest first.
     *
     * @return list<float>
     */
    private function remainingActiveLoanInstallmentAmounts(Member $member): array
    {
        $loans = Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', ['active', 'transferred'])
            ->with(['installments' => fn ($query) => $query
                ->whereIn('status', ['pending', 'overdue'])
                ->orderBy('due_date')
                ->orderBy('installment_number')])
            ->orderBy('id')
            ->get();

        $amounts = [];

        foreach ($loans as $loan) {
            foreach ($loan->installments as $installment) {
                $amounts[] = round((float) $installment->amount, 2);
            }
        }

        return $amounts;
    }

    /**
     * Amount to credit member fund so the current loan(s) restore the pre-loan balance.
     */
    private function fullEarlySettlementRestoreAmount(Member $member, float $currentFund): float
    {
        $loans = Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', ['active', 'transferred'])
            ->with(['installments' => fn ($query) => $query->where('status', 'paid')])
            ->orderBy('id')
            ->get();

        if ($loans->isEmpty()) {
            return 0.0;
        }

        $storedPreLoan = $loans->count() === 1
            ? $loans->first()->member_fund_balance_at_disbursement
            : null;

        if ($storedPreLoan !== null) {
            return round((float) $storedPreLoan - $currentFund, 2);
        }

        $delta = 0.0;

        foreach ($loans as $loan) {
            $paid = 0.0;

            foreach ($loan->installments as $installment) {
                $paid += round((float) $installment->amount, 2);
            }

            $delta += round((float) $loan->member_portion, 2)
                + round((float) $loan->master_portion, 2)
                - $paid;
        }

        return round($delta, 2);
    }

    /**
     * @param  list<float>  $amounts
     */
    private function uniformInstallmentAmount(array $amounts): ?float
    {
        if ($amounts === []) {
            return null;
        }

        $first = round($amounts[0], 2);

        foreach ($amounts as $amount) {
            if (abs(round($amount, 2) - $first) > 0.011) {
                return null;
            }
        }

        return $first;
    }

    private function resolvedStartDate(?string $startDate): Carbon
    {
        if (! filled($startDate)) {
            return BusinessDay::today()->startOfDay();
        }

        try {
            return Carbon::parse($startDate)->startOfDay();
        } catch (Throwable) {
            return BusinessDay::today()->startOfDay();
        }
    }

    private function resolvedContributionAmount(Member $member, int|float|string|null $amount): float
    {
        if ($amount === null || $amount === '') {
            return max(0.0, round((float) $member->monthly_contribution_amount, 2));
        }

        return max(0.0, round((float) $amount, 2));
    }

    /**
     * @param  list<array{kind: string, number: int|null, cycle_month: int, cycle_year: int, cycle_label: string, due_date: string|null, due_label: string|null, amount: float, is_final: bool}>  $emiRows
     * @return list<array{kind: string, number: int|null, cycle_month: int, cycle_year: int, cycle_label: string, due_date: string|null, due_label: string|null, amount: float, is_final: bool}>
     */
    private function applyEarlySettlementToEmiRows(
        array $emiRows,
        ?string $settlementMode,
        int $installmentsCovered,
        float $minInstallment = 0.0,
        float $earlySettlementAmount = 0.0,
    ): array {
        $count = count($emiRows);
        $covered = min($count, max(0, $installmentsCovered));

        if ($settlementMode === null) {
            return $emiRows;
        }

        if ($settlementMode === 'skip_future') {
            // Reality: excess moves to cash, then regular payments apply (schedule length unchanged).
            for ($i = 0; $i < $covered; $i++) {
                $emiRows[$i]['kind'] = 'paid';
                $emiRows[$i]['is_final'] = false;
                if ($minInstallment > 0.00001) {
                    $emiRows[$i]['amount'] = round($minInstallment, 2);
                }
            }

            $appliedFull = $minInstallment > 0.00001
                ? round($covered * $minInstallment, 2)
                : 0.0;
            $remainder = round(max(0.0, $earlySettlementAmount - $appliedFull), 2);

            // Partial leftover reduces the next payable cycle (same as a short regular payment).
            if ($remainder > 0.00001) {
                for ($i = $covered; $i < $count; $i++) {
                    if (($emiRows[$i]['kind'] ?? '') !== 'emi') {
                        continue;
                    }

                    $due = round((float) ($emiRows[$i]['amount'] ?? 0), 2);
                    $emiRows[$i]['amount'] = round(max(0.0, $due - $remainder), 2);

                    break;
                }
            }

            return $emiRows;
        }

        if ($covered <= 0 || $settlementMode !== 'roll_up') {
            return $emiRows;
        }

        for ($i = 0; $i < $covered; $i++) {
            $emiRows[$i]['kind'] = 'rolled_up';
            $emiRows[$i]['is_final'] = false;
        }

        $tailStart = max($covered, $count - $covered);

        for ($i = $tailStart; $i < $count; $i++) {
            $emiRows[$i]['kind'] = 'dropped';
            $emiRows[$i]['is_final'] = false;
        }

        return $emiRows;
    }

    /**
     * @return array{kind: string, number: int|null, cycle_month: int, cycle_year: int, cycle_label: string, due_date: string|null, due_label: string|null, amount: float, is_final: bool}
     */
    private function scheduleRow(
        string $kind,
        Carbon $period,
        ?int $number = null,
        float $amount = 0.0,
        bool $isFinal = false,
    ): array {
        $locale = app()->getLocale();
        $due = $this->repaymentWindows->installmentDueDateForCycle((int) $period->month, (int) $period->year);

        return [
            'kind' => $kind,
            'number' => $number,
            'cycle_month' => (int) $period->month,
            'cycle_year' => (int) $period->year,
            'cycle_label' => $period->copy()->locale($locale)->translatedFormat('F Y'),
            'due_date' => $due->toDateString(),
            'due_label' => $due->copy()->locale($locale)->translatedFormat('j M Y'),
            'amount' => $amount,
            'is_final' => $isFinal,
        ];
    }
}
