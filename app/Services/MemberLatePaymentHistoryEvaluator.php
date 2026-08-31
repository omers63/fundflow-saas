<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use App\Support\ContributionCollectionStatus;
use App\Support\ContributionPolicySettings;
use App\Support\LoanSettings;
use Carbon\Carbon;

/**
 * Counts contribution/repayment cycles settled after their deadline (is_late).
 *
 * Contribution and EMI late history use separate settings thresholds for loan eligibility.
 */
class MemberLatePaymentHistoryEvaluator
{
    /** @var array<string, bool> */
    protected array $lateContributionPeriods = [];

    /** @var array<string, bool> */
    protected array $lateRepaymentPeriods = [];

    public function __construct(
        protected ContributionCycleService $cycles,
        protected MemberDelinquencyEvaluator $delinquencyEvaluator,
    ) {}

    /**
     * @return array{
     *   contribution: array{trailing_consecutive: int, rolling_total: int},
     *   repayment: array{trailing_consecutive: int, rolling_total: int},
     *   trailing_consecutive: int,
     *   rolling_total: int,
     *   last_closed_month: int|null,
     *   last_closed_year: int|null,
     * }
     */
    public function evaluate(Member $member): array
    {
        $this->lateContributionPeriods = [];
        $this->lateRepaymentPeriods = [];

        $now = BusinessDay::now();
        [$lastMonth, $lastYear] = $this->delinquencyEvaluator->lastClosedPeriodMonthYear($now);

        $joined = $member->joined_at instanceof Carbon
            ? $member->joined_at->copy()->startOfMonth()
            : Carbon::parse($member->joined_at)->startOfMonth();

        if ($this->periodKey($lastYear, $lastMonth) < $this->periodKey((int) $joined->year, (int) $joined->month)) {
            return $this->emptyResult();
        }

        $lookback = max(
            ContributionPolicySettings::lateSettlementLookbackMonths(),
            LoanSettings::latePaymentLookbackMonths(),
        );
        $this->warmPeriodCaches($member, $joined, $lastMonth, $lastYear, $lookback);

        $contribution = $this->statsForPeriods(
            $this->lateContributionPeriods,
            $joined,
            $lastMonth,
            $lastYear,
            ContributionPolicySettings::lateSettlementLookbackMonths(),
        );
        $repayment = $this->statsForPeriods(
            $this->lateRepaymentPeriods,
            $joined,
            $lastMonth,
            $lastYear,
            LoanSettings::latePaymentLookbackMonths(),
        );

        return [
            'contribution' => $contribution,
            'repayment' => $repayment,
            // Combined (union of late types per period) — useful for digests; loan gate uses split checks.
            'trailing_consecutive' => max($contribution['trailing_consecutive'], $repayment['trailing_consecutive']),
            'rolling_total' => $contribution['rolling_total'] + $repayment['rolling_total'],
            'last_closed_month' => $lastMonth,
            'last_closed_year' => $lastYear,
        ];
    }

    /**
     * @return array{trailing_consecutive: int, rolling_total: int, last_closed_month: null, last_closed_year: null, contribution: array{trailing_consecutive: int, rolling_total: int}, repayment: array{trailing_consecutive: int, rolling_total: int}}
     */
    private function emptyResult(): array
    {
        $zero = ['trailing_consecutive' => 0, 'rolling_total' => 0];

        return [
            'contribution' => $zero,
            'repayment' => $zero,
            'trailing_consecutive' => 0,
            'rolling_total' => 0,
            'last_closed_month' => null,
            'last_closed_year' => null,
        ];
    }

    /**
     * @param  array<string, bool>  $latePeriods
     * @return array{trailing_consecutive: int, rolling_total: int}
     */
    private function statsForPeriods(
        array $latePeriods,
        Carbon $joined,
        int $lastMonth,
        int $lastYear,
        int $lookback,
    ): array {
        $rollingTotal = 0;
        $cursor = Carbon::create($lastYear, $lastMonth, 1)->startOfMonth();
        for ($i = 0; $i < $lookback; $i++) {
            $month = (int) $cursor->month;
            $year = (int) $cursor->year;

            if ($cursor->lt($joined)) {
                break;
            }

            if ($latePeriods[$this->monthKey($month, $year)] ?? false) {
                $rollingTotal++;
            }

            $cursor->subMonthNoOverflow();
        }

        $trailing = 0;
        $cursor = Carbon::create($lastYear, $lastMonth, 1)->startOfMonth();
        for ($i = 0; $i < 240; $i++) {
            $month = (int) $cursor->month;
            $year = (int) $cursor->year;

            if ($cursor->lt($joined)) {
                break;
            }

            if (! ($latePeriods[$this->monthKey($month, $year)] ?? false)) {
                break;
            }

            $trailing++;
            $cursor->subMonthNoOverflow();
        }

        return [
            'trailing_consecutive' => $trailing,
            'rolling_total' => $rollingTotal,
        ];
    }

    /**
     * @param  array{contribution: array{trailing_consecutive: int, rolling_total: int}, repayment: array{trailing_consecutive: int, rolling_total: int}}  $history
     */
    public function shouldBlockLoanEligibility(array $history): bool
    {
        return $this->shouldBlockFromLateContributions($history['contribution'])
            || $this->shouldBlockFromLateRepayments($history['repayment']);
    }

    /**
     * @param  array{trailing_consecutive: int, rolling_total: int}  $stats
     */
    public function shouldBlockFromLateContributions(array $stats): bool
    {
        return $stats['trailing_consecutive'] >= ContributionPolicySettings::lateSettlementConsecutiveThreshold()
            || $stats['rolling_total'] >= ContributionPolicySettings::lateSettlementRollingThreshold();
    }

    /**
     * @param  array{trailing_consecutive: int, rolling_total: int}  $stats
     */
    public function shouldBlockFromLateRepayments(array $stats): bool
    {
        return $stats['trailing_consecutive'] >= LoanSettings::latePaymentConsecutiveThreshold()
            || $stats['rolling_total'] >= LoanSettings::latePaymentRollingThreshold();
    }

    protected function warmPeriodCaches(
        Member $member,
        Carbon $joined,
        int $lastMonth,
        int $lastYear,
        int $lookbackMonths,
    ): void {
        $startCursor = Carbon::create($lastYear, $lastMonth, 1)->startOfMonth()->subMonths($lookbackMonths - 1);
        $rangeStart = $startCursor->lt($joined) ? $joined->copy() : $startCursor;
        $rangeEnd = Carbon::create($lastYear, $lastMonth, 1)->endOfMonth();

        $this->lateContributionPeriods = Contribution::query()
            ->where('member_id', $member->id)
            ->where('is_late', true)
            ->whereBetween('period', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->where(function ($query): void {
                $query->where('status', 'posted')
                    ->orWhere('collection_status', ContributionCollectionStatus::COLLECTED);
            })
            ->get(['period'])
            ->filter(fn (Contribution $contribution): bool => $contribution->period !== null)
            ->mapWithKeys(function (Contribution $contribution): array {
                $period = Carbon::parse((string) $contribution->period);

                return [$this->monthKey((int) $period->month, (int) $period->year) => true];
            })
            ->all();

        $this->lateRepaymentPeriods = LoanInstallment::query()
            ->whereHas('loan', fn ($query) => $query->where('member_id', $member->id))
            ->where('status', 'paid')
            ->where('is_late', true)
            ->whereBetween('due_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get(['due_date'])
            ->filter(fn (LoanInstallment $installment): bool => $installment->due_date !== null)
            ->mapWithKeys(function (LoanInstallment $installment): array {
                $dueDate = Carbon::parse((string) $installment->due_date);

                return [$this->monthKey((int) $dueDate->month, (int) $dueDate->year) => true];
            })
            ->all();
    }

    protected function periodKey(int $year, int $month): int
    {
        return $year * 12 + $month;
    }

    protected function monthKey(int $month, int $year): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }
}
