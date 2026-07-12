<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CollectionCalendarService
{
    public function __construct(
        protected ContributionCycleService $cycles,
    ) {}

    /**
     * @return array<int, array{
     *     date: string,
     *     to_collect: int,
     *     to_collect_amount: float,
     *     to_collect_emi: int,
     *     to_collect_emi_amount: float,
     *     to_collect_contribution: int,
     *     to_collect_contribution_amount: float,
     *     paid_on: int,
     *     paid_on_amount: float,
     *     paid_on_emi: int,
     *     paid_on_emi_amount: float,
     *     paid_on_contribution: int,
     *     paid_on_contribution_amount: float,
     * }>
     */
    public function monthGrid(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $emiDueGrouped = LoanInstallment::query()
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', ['pending', 'overdue'])
            ->whereHas('loan', fn (Builder $loan): Builder => $loan->whereIn('status', ['active', 'transferred']))
            ->get(['id', 'due_date', 'amount'])
            ->groupBy(fn (LoanInstallment $installment): string => BusinessDay::collectionDateKey($installment->due_date) ?? '');

        $emiPaidGrouped = LoanInstallment::query()
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end->copy()->endOfDay()])
            ->whereHas('loan', fn (Builder $loan): Builder => $loan->whereIn('status', [
                'active',
                'transferred',
                'completed',
                'early_settled',
            ]))
            ->get(['id', 'paid_at', 'amount'])
            ->groupBy(fn (LoanInstallment $installment): string => BusinessDay::collectionDateKey($installment->paid_at) ?? '');

        $contributionPaidGrouped = Contribution::query()
            ->posted()
            ->whereRaw('COALESCE(posted_at, paid_at) BETWEEN ? AND ?', [$start, $end->copy()->endOfDay()])
            ->get(['id', 'posted_at', 'paid_at', 'amount'])
            ->groupBy(fn (Contribution $contribution): string => $this->contributionCollectedOnDate($contribution));

        $contributionDueGrouped = $this->pendingContributionsByCycleDueEnd($start, $end);

        $days = [];

        for ($day = 1; $day <= $end->day; $day++) {
            $date = $start->copy()->day($day)->toDateString();
            $emiDueItems = $emiDueGrouped->get($date, collect());
            $emiPaidItems = $emiPaidGrouped->get($date, collect());
            $contributionPaidItems = $contributionPaidGrouped->get($date, collect());
            $contributionDue = $contributionDueGrouped->get($date, [
                'count' => 0,
                'amount' => 0.0,
            ]);

            $toCollectEmi = $emiDueItems->count();
            $toCollectEmiAmount = round((float) $emiDueItems->sum('amount'), 2);
            $toCollectContribution = (int) $contributionDue['count'];
            $toCollectContributionAmount = round((float) $contributionDue['amount'], 2);
            $paidOnEmi = $emiPaidItems->count();
            $paidOnEmiAmount = round((float) $emiPaidItems->sum('amount'), 2);
            $paidOnContribution = $contributionPaidItems->count();
            $paidOnContributionAmount = round((float) $contributionPaidItems->sum('amount'), 2);

            $days[$day] = [
                'date' => $date,
                'to_collect_emi' => $toCollectEmi,
                'to_collect_emi_amount' => $toCollectEmiAmount,
                'to_collect_contribution' => $toCollectContribution,
                'to_collect_contribution_amount' => $toCollectContributionAmount,
                'to_collect' => $toCollectEmi + $toCollectContribution,
                'to_collect_amount' => round($toCollectEmiAmount + $toCollectContributionAmount, 2),
                'paid_on_emi' => $paidOnEmi,
                'paid_on_emi_amount' => $paidOnEmiAmount,
                'paid_on_contribution' => $paidOnContribution,
                'paid_on_contribution_amount' => $paidOnContributionAmount,
                'paid_on' => $paidOnEmi + $paidOnContribution,
                'paid_on_amount' => round($paidOnEmiAmount + $paidOnContributionAmount, 2),
            ];
        }

        return $days;
    }

    /**
     * @return array{
     *     emis: Collection<int, LoanInstallment>,
     *     contributions: Collection<int, Contribution>,
     * }
     */
    public function itemsForDate(Carbon $date): array
    {
        $dateString = $date->toDateString();

        return [
            'emis' => $this->emisForDate($dateString),
            'contributions' => $this->contributionsForDate($dateString),
        ];
    }

    /**
     * @return Collection<int, LoanInstallment>
     */
    public function emisForDate(string $dateString): Collection
    {
        return LoanInstallment::query()
            ->where(function (Builder $query) use ($dateString): void {
                $query
                    ->where(function (Builder $openDue) use ($dateString): void {
                        $openDue
                            ->whereIn('status', ['pending', 'overdue'])
                            ->whereDate('due_date', $dateString);
                    })
                    ->orWhere(function (Builder $paidOnDate) use ($dateString): void {
                        $paidOnDate
                            ->where('status', 'paid')
                            ->whereNotNull('paid_at')
                            ->whereDate('paid_at', $dateString);
                    });
            })
            ->whereHas('loan', fn (Builder $loan): Builder => $loan->whereIn('status', [
                'active',
                'transferred',
                'completed',
                'early_settled',
            ]))
            ->with(['loan.member'])
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->get();
    }

    /**
     * @return Collection<int, Contribution>
     */
    public function contributionsForDate(string $dateString): Collection
    {
        return Contribution::query()
            ->posted()
            ->where(function (Builder $query) use ($dateString): void {
                $query
                    ->whereDate('posted_at', $dateString)
                    ->orWhere(function (Builder $paidOnly) use ($dateString): void {
                        $paidOnly
                            ->whereNull('posted_at')
                            ->whereNotNull('paid_at')
                            ->whereDate('paid_at', $dateString);
                    });
            })
            ->with('member')
            ->orderByRaw('COALESCE(posted_at, paid_at) asc')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<string, array{count: int, amount: float}>
     */
    protected function pendingContributionsByCycleDueEnd(Carbon $monthStart, Carbon $monthEnd): Collection
    {
        $byDate = collect();
        $cursor = $monthStart->copy()->subMonths(2)->startOfMonth();
        $scanUntil = $monthEnd->copy()->addMonth()->endOfMonth();

        while ($cursor->lessThanOrEqualTo($scanUntil)) {
            $cycleMonth = (int) $cursor->month;
            $cycleYear = (int) $cursor->year;
            $dueEndDate = $this->cycles->cycleDueEndAt($cycleMonth, $cycleYear)->toDateString();

            if ($dueEndDate >= $monthStart->toDateString() && $dueEndDate <= $monthEnd->toDateString()) {
                $pendingMembers = $this->cycles->pendingMembersQueryForPeriod($cycleMonth, $cycleYear)->get();
                $amount = round($pendingMembers->sum(
                    fn (Member $member): float => $this->cycles->requiredCashForMemberPeriod($member, $cycleMonth, $cycleYear),
                ), 2);

                $byDate->put($dueEndDate, [
                    'count' => $pendingMembers->count(),
                    'amount' => $amount,
                ]);
            }

            $cursor->addMonthNoOverflow();
        }

        return $byDate;
    }

    protected function contributionCollectedOnDate(Contribution $contribution): string
    {
        $collectedAt = $contribution->posted_at ?? $contribution->paid_at;

        return BusinessDay::collectionDateKey($collectedAt) ?? '';
    }
}
