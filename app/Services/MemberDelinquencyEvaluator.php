<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Support\ContributionPolicySettings;
use Carbon\Carbon;

/**
 * Computes missed contribution / loan repayment obligations per closed cycle month
 * and derives trailing consecutive miss streak and rolling total miss count.
 */
class MemberDelinquencyEvaluator
{
    /** @var array<string, bool> */
    protected array $exemptFromContributionCache = [];

    /** @var array<string, bool> */
    protected array $postedContributionPeriods = [];

    /** @var array<string, bool> */
    protected array $repaymentMissPeriods = [];

    /** @var list<string> */
    protected array $contributionExemptStarts = [];

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
        $this->exemptFromContributionCache = [];
        $this->postedContributionPeriods = [];
        $this->repaymentMissPeriods = [];
        $this->contributionExemptStarts = [];

        $now = now();
        [$lastM, $lastY] = $this->lastClosedPeriodMonthYear($now);

        $joined = $member->joined_at instanceof Carbon
            ? $member->joined_at->copy()->startOfMonth()
            : Carbon::parse($member->joined_at)->startOfMonth();

        if ($this->periodKey($lastY, $lastM) < $this->periodKey((int) $joined->year, (int) $joined->month)) {
            return [
                'trailing_consecutive' => 0,
                'rolling_total' => 0,
                'last_closed_month' => null,
                'last_closed_year' => null,
            ];
        }

        $lookback = ContributionPolicySettings::totalMissLookbackMonths();
        $this->warmPeriodCaches($member, $joined, $lastM, $lastY);

        $rollingTotal = 0;
        $cursor = Carbon::create($lastY, $lastM, 1)->startOfMonth();
        for ($i = 0; $i < $lookback; $i++) {
            $m = (int) $cursor->month;
            $y = (int) $cursor->year;
            if ($cursor->lt($joined)) {
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
            if ($cursor->lt($joined)) {
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

    public function clearCaches(): void
    {
        $this->exemptFromContributionCache = [];
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
        return $this->contributionMiss($member, $month, $year)
            || $this->repaymentMiss($member, $month, $year);
    }

    protected function contributionMiss(Member $member, int $month, int $year): bool
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $joined = $member->joined_at instanceof Carbon
            ? $member->joined_at->copy()->startOfMonth()
            : Carbon::parse($member->joined_at)->startOfMonth();

        if ($periodStart->lt($joined)) {
            return false;
        }

        if ((float) $member->monthly_contribution_amount <= 0) {
            return false;
        }

        if ($this->isExemptFromContributionsInMonth($member, $month, $year)) {
            return false;
        }

        return ! ($this->postedContributionPeriods[$this->monthKey($month, $year)] ?? false);
    }

    protected function repaymentMiss(Member $member, int $month, int $year): bool
    {
        $deadline = $this->cycles->deadline($month, $year);
        if (now()->lessThanOrEqualTo($deadline)) {
            return false;
        }

        return $this->repaymentMissPeriods[$this->monthKey($month, $year)] ?? false;
    }

    protected function isExemptFromContributionsInMonth(Member $member, int $month, int $year): bool
    {
        $k = "{$year}-{$month}";
        if (array_key_exists($k, $this->exemptFromContributionCache)) {
            return $this->exemptFromContributionCache[$k];
        }

        $monthKey = $this->monthKey($month, $year);
        $v = collect($this->contributionExemptStarts)->contains(
            fn (string $start): bool => $start <= $monthKey
        );

        return $this->exemptFromContributionCache[$k] = $v;
    }

    protected function periodKey(int $year, int $month): int
    {
        return $year * 12 + $month;
    }

    protected function monthKey(int $month, int $year): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }

    protected function warmPeriodCaches(Member $member, Carbon $joined, int $lastMonth, int $lastYear): void
    {
        $endOfLastClosed = Carbon::create($lastYear, $lastMonth, 1)->endOfMonth();

        $this->postedContributionPeriods = Contribution::query()
            ->where('member_id', $member->id)
            ->whereBetween('period', [$joined->toDateString(), $endOfLastClosed->toDateString()])
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
                    ->where('status', 'active')
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

        $this->contributionExemptStarts = Loan::query()
            ->where('member_id', $member->id)
            ->where('status', 'active')
            ->whereDate('disbursed_at', '<=', $endOfLastClosed)
            ->whereHas('installments', fn ($query) => $query->whereIn('status', ['pending', 'overdue']))
            ->get(['disbursed_at'])
            ->filter(fn (Loan $loan): bool => $loan->disbursed_at !== null)
            ->map(function (Loan $loan): string {
                $disbursedAt = Carbon::parse((string) $loan->disbursed_at);

                return $this->monthKey((int) $disbursedAt->month, (int) $disbursedAt->year);
            })
            ->values()
            ->all();
    }
}
