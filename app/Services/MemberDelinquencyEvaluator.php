<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use App\Support\ContributionPolicySettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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
     * Batch evaluation for many members with shared contribution / installment / loan preloads.
     *
     * @param  Collection<int, Member>  $members
     * @return array<int, array{
     *   trailing_consecutive: int,
     *   rolling_total: int,
     *   last_closed_month: int|null,
     *   last_closed_year: int|null,
     * }>
     */
    public function evaluateMany(Collection $members): array
    {
        if ($members->isEmpty()) {
            return [];
        }

        $emptyStats = [
            'trailing_consecutive' => 0,
            'rolling_total' => 0,
            'last_closed_month' => null,
            'last_closed_year' => null,
        ];

        $now = BusinessDay::now();
        [$lastM, $lastY] = $this->lastClosedPeriodMonthYear($now);
        $lookback = ContributionPolicySettings::totalMissLookbackMonths();
        $endOfLastClosed = Carbon::create($lastY, $lastM, 1)->endOfMonth();

        $earliestLiability = null;
        $memberIds = [];

        foreach ($members as $member) {
            $memberId = (int) $member->id;
            $memberIds[] = $memberId;
            $liabilityStart = $member->contributionLiabilityStartMonth();

            if ($liabilityStart !== null && ($earliestLiability === null || $liabilityStart->lt($earliestLiability))) {
                $earliestLiability = $liabilityStart->copy();
            }
        }

        if ($earliestLiability === null) {
            return array_fill_keys($memberIds, $emptyStats);
        }

        $contributionsByMember = $this->preloadPostedContributionPeriods($memberIds, $earliestLiability, $endOfLastClosed);
        $repaymentMissByMember = $this->preloadRepaymentMissPeriods($memberIds, $endOfLastClosed);
        $graceLoansByMember = $this->preloadLoanGraceCycles($memberIds);
        $repaymentLoansByMember = $this->preloadLoanRepaymentCycles($memberIds);

        $results = [];

        foreach ($members as $member) {
            $memberId = (int) $member->id;
            $liabilityStart = $member->contributionLiabilityStartMonth();

            if ($liabilityStart === null) {
                $results[$memberId] = $emptyStats;

                continue;
            }

            if ($this->periodKey($lastY, $lastM) < $this->periodKey((int) $liabilityStart->year, (int) $liabilityStart->month)) {
                $results[$memberId] = $emptyStats;

                continue;
            }

            $postedContributionPeriods = $contributionsByMember[$memberId] ?? [];
            $repaymentMissPeriods = $repaymentMissByMember[$memberId] ?? [];

            $rollingTotal = 0;
            $cursor = Carbon::create($lastY, $lastM, 1)->startOfMonth();
            for ($i = 0; $i < $lookback; $i++) {
                $m = (int) $cursor->month;
                $y = (int) $cursor->year;
                if ($cursor->lt($liabilityStart)) {
                    break;
                }
                if (
                    $this->periodHasMissWithPreload(
                        $member,
                        $m,
                        $y,
                        $postedContributionPeriods,
                        $repaymentMissPeriods,
                        $graceLoansByMember,
                        $repaymentLoansByMember,
                    )
                ) {
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
                if (
                    ! $this->periodHasMissWithPreload(
                        $member,
                        $m,
                        $y,
                        $postedContributionPeriods,
                        $repaymentMissPeriods,
                        $graceLoansByMember,
                        $repaymentLoansByMember,
                    )
                ) {
                    break;
                }
                $trailing++;
                $cursor->subMonthNoOverflow();
            }

            $results[$memberId] = [
                'trailing_consecutive' => $trailing,
                'rolling_total' => $rollingTotal,
                'last_closed_month' => $lastM,
                'last_closed_year' => $lastY,
            ];
        }

        return $results;
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

    /**
     * @param  array<string, bool>  $postedContributionPeriods
     * @param  array<string, bool>  $repaymentMissPeriods
     * @param  array<int, list<array{first_repayment_month: ?int, first_repayment_year: ?int}>>  $graceLoansByMember
     * @param  array<int, list<array{
     *     disbursed_at: string,
     *     settled_at: ?string,
     *     completed_at: ?string,
     *     status: string
     * }>>  $repaymentLoansByMember
     */
    protected function periodHasMissWithPreload(
        Member $member,
        int $month,
        int $year,
        array $postedContributionPeriods,
        array $repaymentMissPeriods,
        array $graceLoansByMember,
        array $repaymentLoansByMember,
    ): bool {
        if (! $this->periodCountsForDelinquency($member, $month, $year)) {
            return false;
        }

        return $this->contributionMissWithPreload(
            $member,
            $month,
            $year,
            $postedContributionPeriods,
            $graceLoansByMember,
            $repaymentLoansByMember,
        ) || $this->repaymentMissWithPreload($month, $year, $repaymentMissPeriods);
    }

    /**
     * @param  array<string, bool>  $postedContributionPeriods
     * @param  array<int, list<array{first_repayment_month: ?int, first_repayment_year: ?int}>>  $graceLoansByMember
     * @param  array<int, list<array{
     *     disbursed_at: string,
     *     settled_at: ?string,
     *     completed_at: ?string,
     *     status: string
     * }>>  $repaymentLoansByMember
     */
    protected function contributionMissWithPreload(
        Member $member,
        int $month,
        int $year,
        array $postedContributionPeriods,
        array $graceLoansByMember,
        array $repaymentLoansByMember,
    ): bool {
        if ((float) $member->monthly_contribution_amount <= 0) {
            return false;
        }

        if (
            $this->isExemptFromContributionsWithPreload(
                (int) $member->id,
                $month,
                $year,
                $graceLoansByMember,
                $repaymentLoansByMember,
            )
        ) {
            return false;
        }

        return ! ($postedContributionPeriods[$this->monthKey($month, $year)] ?? false);
    }

    /**
     * @param  array<string, bool>  $repaymentMissPeriods
     */
    protected function repaymentMissWithPreload(int $month, int $year, array $repaymentMissPeriods): bool
    {
        $deadline = $this->cycles->deadline($month, $year);
        if (BusinessDay::now()->lessThanOrEqualTo($deadline)) {
            return false;
        }

        return $repaymentMissPeriods[$this->monthKey($month, $year)] ?? false;
    }

    /**
     * @param  array<int, list<array{first_repayment_month: ?int, first_repayment_year: ?int}>>  $graceLoansByMember
     * @param  array<int, list<array{
     *     disbursed_at: string,
     *     settled_at: ?string,
     *     completed_at: ?string,
     *     status: string
     * }>>  $repaymentLoansByMember
     */
    protected function isExemptFromContributionsWithPreload(
        int $memberId,
        int $month,
        int $year,
        array $graceLoansByMember,
        array $repaymentLoansByMember,
    ): bool {
        if ($this->isInLoanGracePeriodForCycle($memberId, $month, $year, $graceLoansByMember)) {
            return true;
        }

        return $this->wasInLoanRepaymentCycle($memberId, $month, $year, $repaymentLoansByMember);
    }

    /**
     * @param  array<int, list<array{first_repayment_month: ?int, first_repayment_year: ?int}>>  $graceLoansByMember
     */
    protected function isInLoanGracePeriodForCycle(
        int $memberId,
        int $month,
        int $year,
        array $graceLoansByMember,
    ): bool {
        foreach ($graceLoansByMember[$memberId] ?? [] as $loan) {
            $firstMonth = $loan['first_repayment_month'];
            $firstYear = $loan['first_repayment_year'];

            if ($firstMonth === null) {
                return true;
            }

            if ($firstYear > $year) {
                return true;
            }

            if ($firstYear === $year && $firstMonth > $month) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, list<array{
     *     disbursed_at: string,
     *     settled_at: ?string,
     *     completed_at: ?string,
     *     status: string
     * }>>  $repaymentLoansByMember
     */
    protected function wasInLoanRepaymentCycle(
        int $memberId,
        int $month,
        int $year,
        array $repaymentLoansByMember,
    ): bool {
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodKey = sprintf('%04d-%02d', $year, $month);

        foreach ($repaymentLoansByMember[$memberId] ?? [] as $loan) {
            $disbursedAt = Carbon::parse($loan['disbursed_at']);
            $disbursedKey = sprintf('%04d-%02d', (int) $disbursedAt->year, (int) $disbursedAt->month);

            if ($disbursedKey > $periodKey) {
                continue;
            }

            $cycleEnd = $loan['settled_at'] ?? $loan['completed_at'];

            if ($cycleEnd === null) {
                if (in_array($loan['status'], ['active', 'transferred'], true)) {
                    return true;
                }

                continue;
            }

            if (Carbon::parse($cycleEnd)->endOfMonth()->greaterThanOrEqualTo($periodStart)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $memberIds
     * @return array<int, array<string, bool>>
     */
    protected function preloadPostedContributionPeriods(
        array $memberIds,
        Carbon $earliestLiability,
        Carbon $endOfLastClosed,
    ): array {
        if ($memberIds === []) {
            return [];
        }

        $grouped = [];

        Contribution::query()
            ->whereIn('member_id', $memberIds)
            ->whereBetween('period', [$earliestLiability->toDateString(), $endOfLastClosed->toDateString()])
            ->get(['member_id', 'period'])
            ->each(function (Contribution $contribution) use (&$grouped): void {
                if ($contribution->period === null) {
                    return;
                }

                $period = Carbon::parse((string) $contribution->period);
                $memberId = (int) $contribution->member_id;
                $grouped[$memberId][$this->monthKey((int) $period->month, (int) $period->year)] = true;
            });

        return $grouped;
    }

    /**
     * @param  list<int>  $memberIds
     * @return array<int, array<string, bool>>
     */
    protected function preloadRepaymentMissPeriods(array $memberIds, Carbon $endOfLastClosed): array
    {
        if ($memberIds === []) {
            return [];
        }

        $grouped = [];

        LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->whereDate('due_date', '<=', $endOfLastClosed)
            ->whereHas(
                'loan',
                fn ($query) => $query
                    ->whereIn('member_id', $memberIds)
                    ->whereIn('status', ['active', 'transferred'])
            )
            ->with('loan:id,member_id')
            ->get(['loan_id', 'due_date'])
            ->each(function (LoanInstallment $installment) use (&$grouped): void {
                if ($installment->due_date === null || $installment->loan === null) {
                    return;
                }

                $dueDate = Carbon::parse((string) $installment->due_date);
                $memberId = (int) $installment->loan->member_id;
                $grouped[$memberId][$this->monthKey((int) $dueDate->month, (int) $dueDate->year)] = true;
            });

        return $grouped;
    }

    /**
     * @param  list<int>  $memberIds
     * @return array<int, list<array{first_repayment_month: ?int, first_repayment_year: ?int}>>
     */
    protected function preloadLoanGraceCycles(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $grouped = [];

        Loan::query()
            ->whereIn('member_id', $memberIds)
            ->whereIn('status', ['active', 'approved'])
            ->where('has_grace_cycle', true)
            ->get(['member_id', 'first_repayment_month', 'first_repayment_year'])
            ->each(function (Loan $loan) use (&$grouped): void {
                $grouped[(int) $loan->member_id][] = [
                    'first_repayment_month' => $loan->first_repayment_month !== null
                        ? (int) $loan->first_repayment_month
                        : null,
                    'first_repayment_year' => $loan->first_repayment_year !== null
                        ? (int) $loan->first_repayment_year
                        : null,
                ];
            });

        return $grouped;
    }

    /**
     * @param  list<int>  $memberIds
     * @return array<int, list<array{
     *     disbursed_at: string,
     *     settled_at: ?string,
     *     completed_at: ?string,
     *     status: string
     * }>>
     */
    protected function preloadLoanRepaymentCycles(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $grouped = [];

        Loan::query()
            ->whereIn('member_id', $memberIds)
            ->whereNotNull('disbursed_at')
            ->whereIn('status', ['active', 'transferred', 'completed', 'early_settled'])
            ->get(['member_id', 'disbursed_at', 'settled_at', 'completed_at', 'status'])
            ->each(function (Loan $loan) use (&$grouped): void {
                $grouped[(int) $loan->member_id][] = [
                    'disbursed_at' => (string) $loan->disbursed_at,
                    'settled_at' => $loan->settled_at !== null ? (string) $loan->settled_at : null,
                    'completed_at' => $loan->completed_at !== null ? (string) $loan->completed_at : null,
                    'status' => (string) $loan->status,
                ];
            });

        return $grouped;
    }

    protected function warmPeriodCaches(Member $member, Carbon $liabilityStart, int $lastMonth, int $lastYear): void
    {
        $endOfLastClosed = Carbon::create($lastYear, $lastMonth, 1)->endOfMonth();
        $memberId = (int) $member->id;

        $this->postedContributionPeriods = $this->preloadPostedContributionPeriods(
            [$memberId],
            $liabilityStart,
            $endOfLastClosed,
        )[$memberId] ?? [];

        $this->repaymentMissPeriods = $this->preloadRepaymentMissPeriods(
            [$memberId],
            $endOfLastClosed,
        )[$memberId] ?? [];
    }
}
