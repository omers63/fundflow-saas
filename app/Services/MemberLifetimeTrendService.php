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
use Illuminate\Support\Collection;
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
            'member_lifetime_trend_cycle4:'.tenant()?->getTenantKey().':'.$member->id.':'.BusinessDay::now()->toDateString(),
            now()->addMinutes(5),
            fn (): array => $this->build($member),
        );
    }

    /**
     * Which cycle a repayment belongs to: the cycle of the EMI it settles. Migrated loans have repayment rows with no
     * installment link, so each (loan, day) total is allocated over that day's paid installments in installment order, up
     * to each EMI's amount; any remainder stays with its own payment date. Mirrors Nest allocatePaidEvents.
     *
     * @param  Collection<int, LoanRepayment>  $repayments
     * @param  Collection<int, LoanInstallment>  $installments
     * @param  list<int>  $loansWithRepayments
     * @return list<array{loan_id: int, anchor: Carbon, on: Carbon, amount: float}>
     */
    private function allocatePaidEvents(Collection $repayments, Collection $installments, array $loansWithRepayments): array
    {
        $events = [];
        $cap = static fn (LoanInstallment $i): float => (float) $i->amount_collected > 0 ? (float) $i->amount_collected : (float) $i->amount;
        $paidAt = static fn (LoanInstallment $i): ?Carbon => $i->paid_at ?? $i->waived_at;
        $withRepayments = array_flip($loansWithRepayments);

        foreach ($installments as $i) {
            $on = $paidAt($i);
            if (isset($withRepayments[$i->loan_id]) || $on === null) {
                continue;
            }
            $events[] = ['loan_id' => (int) $i->loan_id, 'anchor' => Carbon::parse($i->due_date), 'on' => Carbon::parse($on), 'amount' => $cap($i)];
        }

        $groups = [];
        foreach ($repayments as $r) {
            $on = Carbon::parse((string) $r->paid_at);
            $k = $r->loan_id.'|'.$on->format('Y-m-d');
            $groups[$k] ??= ['loan_id' => (int) $r->loan_id, 'on' => $on, 'total' => 0.0];
            $groups[$k]['total'] += (float) $r->amount;
        }

        $byLoanDay = [];
        foreach ($installments as $i) {
            $on = $paidAt($i);
            if (! isset($withRepayments[$i->loan_id]) || $on === null) {
                continue;
            }
            $byLoanDay[$i->loan_id.'|'.Carbon::parse($on)->format('Y-m-d')][] = $i;
        }

        foreach ($groups as $k => $group) {
            $remaining = $group['total'];
            $list = collect($byLoanDay[$k] ?? [])->sortBy('installment_number');
            foreach ($list as $inst) {
                if ($remaining <= 0.005) {
                    break;
                }
                $take = min($remaining, $cap($inst));
                if ($take > 0.005) {
                    $events[] = ['loan_id' => $group['loan_id'], 'anchor' => Carbon::parse($inst->due_date), 'on' => $group['on'], 'amount' => $take];
                    $remaining -= $take;
                }
            }
            if ($remaining > 0.005) {
                $events[] = ['loan_id' => $group['loan_id'], 'anchor' => $group['on'], 'on' => $group['on'], 'amount' => $remaining];
            }
        }

        return $events;
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
        // A repayment belongs to the cycle of the EMI it settles (due date), not the cycle the money arrived in; capped at
        // the open cycle. Totals are unchanged, only redistributed over cycles.
        $openKey = sprintf('%04d-%02d', $openYear, $openMonth);
        $credited = [];
        $endKeyByLoan = [];
        if ($loanIds !== []) {
            $allRepay = $withRepayments === []
                ? collect()
                : LoanRepayment::query()->whereIn('loan_id', $withRepayments)->whereNotNull('paid_at')->get(['loan_id', 'amount', 'paid_at']);
            $allPaid = LoanInstallment::query()
                ->whereIn('loan_id', $loanIds)
                ->where(fn ($q) => $q->where('status', 'paid')->orWhere(fn ($w) => $w->where('status', 'waived')->where('amount_collected', '>', 0)))
                ->get(['loan_id', 'installment_number', 'due_date', 'amount', 'amount_collected', 'paid_at', 'waived_at']);

            // The repayment phase ends when the loan is settled / completed (the same rule as the contribution exemption).
            // Installments prepaid for cycles after that are shown where the cash actually arrived.
            $cycleKeyOf = fn (mixed $date): string => vsprintf('%2$04d-%1$02d', $this->cycles->cyclePeriodForDueDate(Carbon::parse((string) $date)));
            foreach (Loan::query()->whereIn('id', $loanIds)->get(['id', 'settled_at', 'completed_at']) as $loan) {
                $loanEnd = $loan->settled_at ?? $loan->completed_at;
                if ($loanEnd !== null) {
                    $endKeyByLoan[(int) $loan->id] = $cycleKeyOf($loanEnd);
                }
            }

            foreach ($this->allocatePaidEvents($allRepay, $allPaid, $withRepayments) as $event) {
                $key = $cycleKeyOf($event['anchor']);
                $endKey = $endKeyByLoan[(int) $event['loan_id']] ?? null;
                if ($endKey !== null && $key > $endKey) {
                    $key = $cycleKeyOf($event['on']);
                }
                $credited[$key > $openKey ? $openKey : $key][] = $event;
            }
        }
        $dueInst = $loanIds === []
            ? collect()
            : LoanInstallment::query()
                ->whereIn('loan_id', $loanIds)
                ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
                ->where('status', '!=', 'waived')
                ->get(['loan_id', 'amount', 'due_date']);
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

            $cycleEvents = collect($credited[$key] ?? []);
            $timingOf = static fn (Carbon $on): ?string => $on->lt($cs) ? 'early' : ($on->gt($ce) ? 'late' : null);
            $repaid = (float) $cycleEvents->sum('amount');
            $repaidCount = $cycleEvents->count();
            $due = (float) $dueInst
                ->filter(fn ($r): bool => $inCycle($r->due_date) && ! (isset($endKeyByLoan[(int) $r->loan_id]) && $key > $endKeyByLoan[(int) $r->loan_id]))
                ->sum(fn ($r): float => (float) $r->amount);
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
                // Contributions always belong to their own period's cycle; the date is shown with early / late.
                'contributionsPostedOn' => $posted
                    ->map(fn (Contribution $c): ?Carbon => $c->posted_at ?? $c->paid_at)
                    ->filter()
                    ->map(fn (Carbon $on): array => ['on' => $on->format('Y-m-d'), 'timing' => $timingOf($on)])
                    ->sortBy('on')->values()->all(),
                'repaymentsPaidOn' => $cycleEvents
                    ->map(fn (array $e): array => ['on' => $e['on']->format('Y-m-d'), 'amount' => round($e['amount'], 2), 'timing' => $timingOf($e['on'])])
                    ->sortBy('on')->values()->all(),
                'disbursedOn' => $disbursements->filter(fn ($r): bool => $inCycle($r->disbursed_at))
                    ->map(fn ($r): array => ['on' => Carbon::parse((string) $r->disbursed_at)->format('Y-m-d'), 'amount' => (float) $r->amount])
                    ->sortBy('on')->values()->all(),
            ];
        }

        return $rows;
    }
}
