<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use App\Support\ContributionPolicySettings;
use Carbon\Carbon;

/**
 * Computes missed contribution / loan repayment obligations per closed cycle month
 * and derives trailing consecutive miss streak and rolling total miss count.
 */
class MemberDelinquencyEvaluator
{
    /** @var array<string, bool> */
    protected array $postedContributionPeriods = [];

    /** @var array<string, bool> */
    protected array $repaymentMissPeriods = [];

    public function __construct(
        protected ContributionCycleService $cycles,
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
        $this->postedContributionPeriods = [];
        $this->repaymentMissPeriods = [];

        $now = BusinessDay::now();
        [$lastM, $lastY] = $this->lastClosedPeriodMonthYear($now);

        $liabilityStart = $member->contributionLiabilityStartMonth();

        if ($liabilityStart === null) {
            return [
                'trailing_consecutive' => 0,
                'rolling_total' => 0,
                'last_closed_month' => null,
                'last_closed_year' => null,
            ];
        }

        if ($this->periodKey($lastY, $lastM) < $this->periodKey((int) $liabilityStart->year, (int) $liabilityStart->month)) {
            return [
                'trailing_consecutive' => 0,
                'rolling_total' => 0,
                'last_closed_month' => null,
                'last_closed_year' => null,
            ];
        }

        $lookback = ContributionPolicySettings::totalMissLookbackMonths();
        $this->warmPeriodCaches($member, $liabilityStart, $lastM, $lastY);

        $rollingTotal = 0;
        $cursor = Carbon::create($lastY, $lastM, 1)->startOfMonth();
        for ($i = 0; $i < $lookback; $i++) {
            $m = (int) $cursor->month;
            $y = (int) $cursor->year;
            if ($cursor->lt($liabilityStart)) {
                break;
            }
            if ($this->periodHasMiss($member, $m, $y)) {
                $rollingTotal++;
            }
            $cursor->subMonthNoOverflow();
        }

        $trailing = 0;
        $cursor = Carbon::create($lastY, $lastM, 1)->startOfMonth();
        for ($i = 0; $i < 240; $i++) {
            $m = (int) $cursor->month;
            $y = (int) $cursor->year;
            if ($cursor->lt($liabilityStart)) {
                break;
            }
            if (! $this->periodHasMiss($member, $m, $y)) {
                break;
            }
            $trailing++;
            $cursor->subMonthNoOverflow();
        }

        return [
            'trailing_consecutive' => $trailing,
            'rolling_total' => $rollingTotal,
            'last_closed_month' => $lastM,
            'last_closed_year' => $lastY,
        ];
    }

    public function shouldSuspend(int $trailingConsecutive, int $rollingTotal): bool
    {
        return $trailingConsecutive >= ContributionPolicySettings::consecutiveMissThreshold()
            || $rollingTotal >= ContributionPolicySettings::totalMissThreshold();
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function lastClosedPeriodMonthYear(Carbon $now): array
    {
        $cursor = $now->copy()->startOfMonth();
        for ($i = 0; $i < 240; $i++) {
            $m = (int) $cursor->month;
            $y = (int) $cursor->year;
            if ($now->greaterThan($this->cycles->deadline($m, $y))) {
                return [$m, $y];
            }
            $cursor->subMonthNoOverflow();
        }

        $fallback = $now->copy()->subMonthNoOverflow();

        return [(int) $fallback->month, (int) $fallback->year];
    }

    protected function periodHasMiss(Member $member, int $month, int $year): bool
    {
        if (! $this->periodCountsForDelinquency($member, $month, $year)) {
            return false;
        }

        return $this->contributionMiss($member, $month, $year)
            || $this->repaymentMiss($member, $month, $year);
    }

    protected function periodCountsForDelinquency(Member $member, int $month, int $year): bool
    {
        $liabilityStart = $member->contributionLiabilityStartMonth();

        if ($liabilityStart === null) {
            return false;
        }

        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();

        return $periodStart->greaterThanOrEqualTo($liabilityStart);
    }

    protected function contributionMiss(Member $member, int $month, int $year): bool
    {
        if ((float) $member->monthly_contribution_amount <= 0) {
            return false;
        }

        if ($member->isExemptFromContributions($month, $year)) {
            return false;
        }

        return ! ($this->postedContributionPeriods[$this->monthKey($month, $year)] ?? false);
    }

    protected function repaymentMiss(Member $member, int $month, int $year): bool
    {
        $deadline = $this->cycles->deadline($month, $year);
        if (BusinessDay::now()->lessThanOrEqualTo($deadline)) {
            return false;
        }

        return $this->repaymentMissPeriods[$this->monthKey($month, $year)] ?? false;
    }

    protected function periodKey(int $year, int $month): int
    {
        return $year * 12 + $month;
    }

    protected function monthKey(int $month, int $year): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }

    protected function warmPeriodCaches(Member $member, Carbon $liabilityStart, int $lastMonth, int $lastYear): void
    {
        $endOfLastClosed = Carbon::create($lastYear, $lastMonth, 1)->endOfMonth();

        $this->postedContributionPeriods = Contribution::query()
            ->where('member_id', $member->id)
            ->whereBetween('period', [$liabilityStart->toDateString(), $endOfLastClosed->toDateString()])
            ->get(['period'])
            ->filter(fn (Contribution $contribution): bool => $contribution->period !== null)
            ->mapWithKeys(function (Contribution $contribution): array {
                $period = Carbon::parse((string) $contribution->period);

                return [$this->monthKey((int) $period->month, (int) $period->year) => true];
            })
            ->all();

        $this->repaymentMissPeriods = LoanInstallment::query()
            ->whereHas(
                'loan',
                fn ($query) => $query
                    ->where('member_id', $member->id)
                    ->whereIn('status', ['active', 'transferred'])
            )
            ->whereIn('status', ['pending', 'overdue'])
            ->whereDate('due_date', '<=', $endOfLastClosed)
            ->get(['due_date'])
            ->filter(fn (LoanInstallment $installment): bool => $installment->due_date !== null)
            ->mapWithKeys(function (LoanInstallment $installment): array {
                $dueDate = Carbon::parse((string) $installment->due_date);

                return [$this->monthKey((int) $dueDate->month, (int) $dueDate->year) => true];
            })
            ->all();
    }
}
