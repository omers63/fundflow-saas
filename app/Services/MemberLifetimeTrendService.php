<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanDisbursement;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Whole-lifetime contributions, loan repayments and loan disbursements per calendar month for one member
 * (first cycle → open cycle). Feeds the "Contributions & repayments — lifetime" chart on the admin member
 * page and the member portal overview.
 *
 * Repayments come from `loan_repayments` when the loan has any (migrated loans), otherwise from paid installments.
 */
final class MemberLifetimeTrendService
{
    public function __construct(private readonly ContributionCycleService $cycles) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forMember(Member $member): array
    {
        return Cache::remember(
            'member_lifetime_trend_cycle2:'.tenant()?->getTenantKey().':'.$member->id.':'.BusinessDay::now()->toDateString(),
            now()->addMinutes(5),
            fn (): array => $this->build($member),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(Member $member): array
    {
        // Cycle-based: each bucket is a contribution cycle (cycle start day → day before the next start), labelled
        // by the cycle's period month, from the member's first cycle up to the open cycle.
        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        $firstContribution = Contribution::query()->where('member_id', $member->id)->orderBy('period')->value('period');
        [$fm, $fy] = $member->joined_at !== null
            ? $this->cycles->cyclePeriodForDueDate(Carbon::parse($member->joined_at))
            : [$openMonth, $openYear];
        if ($firstContribution !== null) {
            $first = Carbon::parse((string) $firstContribution);
            if (($first->year * 12 + $first->month) < ($fy * 12 + $fm)) {
                $fm = (int) $first->month;
                $fy = (int) $first->year;
            }
        }
        $count = max(6, min(600, (($openYear - $fy) * 12) + ($openMonth - $fm) + 1));
        $cycleList = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $idx = ($openYear * 12) + ($openMonth - 1) - $i;
            $y = intdiv($idx, 12);
            $m = ($idx % 12) + 1;
            $cycleList[] = [
                'm' => $m,
                'y' => $y,
                'start' => $this->cycles->cycleStartAt($m, $y),
                'end' => $this->cycles->cycleDueEndAt($m, $y),
            ];
        }
        $start = $cycleList[0]['start']->copy();
        $end = $cycleList[$count - 1]['end']->copy();
        $periodStart = Carbon::create($cycleList[0]['y'], $cycleList[0]['m'], 1)->startOfMonth();

        $contributions = Contribution::query()
            ->where('member_id', $member->id)
            ->where('period', '>=', $periodStart->toDateString())
            ->get(['period', 'status', 'amount', 'amount_due', 'posted_at', 'paid_at']);
        $byPeriod = [];
        foreach ($contributions as $c) {
            $key = Contribution::normalizePeriodKey($c->period);
            if ($key !== null) {
                $byPeriod[substr($key, 0, 7)][] = $c;
            }
        }

        $loanIds = Loan::query()->where('member_id', $member->id)->pluck('id')->all();
        $withRepayments = $loanIds === []
            ? []
            : LoanRepayment::query()->whereIn('loan_id', $loanIds)->distinct()->pluck('loan_id')->all();
        $repayRows = $withRepayments === []
            ? collect()
            : LoanRepayment::query()->whereIn('loan_id', $withRepayments)->whereBetween('paid_at', [$start, $end])->get(['amount', 'paid_at']);
        $paidInst = $loanIds === []
            ? collect()
            : LoanInstallment::query()
                ->whereIn('loan_id', array_values(array_diff($loanIds, $withRepayments)))
                ->where('status', 'paid')
                ->whereBetween('paid_at', [$start, $end])
                ->get(['amount', 'amount_collected', 'paid_at']);
        $dueInst = $loanIds === []
            ? collect()
            : LoanInstallment::query()
                ->whereIn('loan_id', $loanIds)
                ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
                ->where('status', '!=', 'waived')
                ->get(['amount', 'due_date']);
        $disbursements = $loanIds === []
            ? collect()
            : LoanDisbursement::query()->whereIn('loan_id', $loanIds)->whereBetween('disbursed_at', [$start, $end])->get(['amount', 'disbursed_at']);

        $monthly = (float) $member->monthly_contribution_amount;
        $rows = [];
        foreach ($cycleList as $cycle) {
            $m = $cycle['m'];
            $y = $cycle['y'];
            $month = Carbon::create($y, $m, 1);
            $key = $month->format('Y-m');
            $cs = $cycle['start'];
            $ce = $cycle['end'];
            $inCycle = static fn (mixed $date): bool => $date !== null && Carbon::parse((string) $date)->betweenIncluded($cs, $ce);

            $monthContribs = collect($byPeriod[$key] ?? []);
            $posted = $monthContribs->where('status', 'posted');
            $postedAmount = (float) $posted->sum(fn (Contribution $c): float => (float) $c->amount);
            $liable = $member->status === 'active' && $monthly > 0 && $this->cycles->memberIsLiableForContributionPeriod($member, $m, $y);
            $periodRow = $monthContribs->first();
            $expected = $liable ? (float) ($periodRow?->amount_due ?? $periodRow?->amount ?? $monthly) : 0.0;

            $repaid = (float) $repayRows->filter(fn ($r): bool => $inCycle($r->paid_at))->sum(fn ($r): float => (float) $r->amount)
                + (float) $paidInst->filter(fn ($r): bool => $inCycle($r->paid_at))
                    ->sum(fn ($r): float => (float) $r->amount_collected > 0 ? (float) $r->amount_collected : (float) $r->amount);
            $repaidCount = $repayRows->filter(fn ($r): bool => $inCycle($r->paid_at))->count()
                + $paidInst->filter(fn ($r): bool => $inCycle($r->paid_at))->count();
            $due = (float) $dueInst->filter(fn ($r): bool => $inCycle($r->due_date))->sum(fn ($r): float => (float) $r->amount);
            $disbursed = (float) $disbursements->filter(fn ($r): bool => $inCycle($r->disbursed_at))->sum(fn ($r): float => (float) $r->amount);

            $rows[] = [
                'key' => $key,
                'label' => $month->locale(app()->getLocale())->translatedFormat('M').' '.$month->format('y'),
                'contributionsPosted' => round($postedAmount, 2),
                'contributionsExpected' => round($expected, 2),
                'contributionRate' => $expected > 0 ? (int) round(($postedAmount / $expected) * 100) : null,
                'repaymentsPaid' => round($repaid, 2),
                'repaymentsCount' => $repaidCount,
                'repaymentsDue' => round($due, 2),
                'repaymentRate' => $due > 0 ? (int) round(($repaid / $due) * 100) : null,
                'disbursed' => round($disbursed, 2),
                // "Paid on" dates for the chart pop-ups.
                'contributionsPostedOn' => $posted
                    ->map(fn (Contribution $c): ?string => ($c->posted_at ?? $c->paid_at)?->format('Y-m-d'))
                    ->filter()->sort()->values()->all(),
                'repaymentsPaidOn' => $repayRows->filter(fn ($r): bool => $inCycle($r->paid_at))
                    ->map(fn ($r): array => ['on' => Carbon::parse((string) $r->paid_at)->format('Y-m-d'), 'amount' => (float) $r->amount])
                    ->concat($paidInst->filter(fn ($r): bool => $inCycle($r->paid_at))
                        ->map(fn ($r): array => ['on' => Carbon::parse((string) $r->paid_at)->format('Y-m-d'), 'amount' => (float) $r->amount_collected > 0 ? (float) $r->amount_collected : (float) $r->amount]))
                    ->sortBy('on')->values()->all(),
                'disbursedOn' => $disbursements->filter(fn ($r): bool => $inCycle($r->disbursed_at))
                    ->map(fn ($r): array => ['on' => Carbon::parse((string) $r->disbursed_at)->format('Y-m-d'), 'amount' => (float) $r->amount])
                    ->sortBy('on')->values()->all(),
            ];
        }

        return $rows;
    }
}
