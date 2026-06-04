<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Support\ContributionCollectionStatus;
use App\Support\LoanSettings;
use Carbon\Carbon;

/**
 * Counts contribution/repayment cycles settled after their deadline (is_late).
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

        $now = now();
        [$lastMonth, $lastYear] = $this->delinquencyEvaluator->lastClosedPeriodMonthYear($now);

        $joined = $member->joined_at instanceof Carbon
            ? $member->joined_at->copy()->startOfMonth()
            : Carbon::parse($member->joined_at)->startOfMonth();

        if ($this->periodKey($lastYear, $lastMonth) < $this->periodKey((int) $joined->year, (int) $joined->month)) {
            return [
                'trailing_consecutive' => 0,
                'rolling_total' => 0,
                'last_closed_month' => null,
                'last_closed_year' => null,
            ];
        }

        $lookback = LoanSettings::latePaymentLookbackMonths();
        $this->warmPeriodCaches($member, $joined, $lastMonth, $lastYear, $lookback);

        $rollingTotal = 0;
        $cursor = Carbon::create($lastYear, $lastMonth, 1)->startOfMonth();
        for ($i = 0; $i < $lookback; $i++) {
            $month = (int) $cursor->month;
            $year = (int) $cursor->year;

            if ($cursor->lt($joined)) {
                break;
            }

            if ($this->periodHadLatePayment($month, $year)) {
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

            if (! $this->periodHadLatePayment($month, $year)) {
                break;
            }

            $trailing++;
            $cursor->subMonthNoOverflow();
        }

        return [
            'trailing_consecutive' => $trailing,
            'rolling_total' => $rollingTotal,
            'last_closed_month' => $lastMonth,
            'last_closed_year' => $lastYear,
        ];
    }

    public function shouldBlockLoanEligibility(int $trailingConsecutive, int $rollingTotal): bool
    {
        return $trailingConsecutive >= LoanSettings::latePaymentConsecutiveThreshold()
            || $rollingTotal >= LoanSettings::latePaymentRollingThreshold();
    }

    protected function periodHadLatePayment(int $month, int $year): bool
    {
        $key = $this->monthKey($month, $year);

        return ($this->lateContributionPeriods[$key] ?? false)
            || ($this->lateRepaymentPeriods[$key] ?? false);
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
