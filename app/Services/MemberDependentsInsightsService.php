<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\Insights\InsightFormatter;
use App\Support\Tenant\CurrentMember;
use Illuminate\Support\Collection;

final class MemberDependentsInsightsService
{
    public function __construct(
        protected ContributionCycleService $cycles,
        protected LoanDelinquencyService $delinquency,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?Member $parent = null): array
    {
        $parent = $parent ?? CurrentMember::get();

        if ($parent === null || ! $parent->isParent()) {
            return [];
        }

        $dependents = $parent->dependents()
            ->with(['cashAccount', 'fundAccount'])
            ->orderBy('name')
            ->get();

        if ($dependents->isEmpty()) {
            return [];
        }

        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        $openPeriodLabel = $this->cycles->periodLabel($openMonth, $openYear);

        $activeCount = $dependents->where('status', 'active')->count();
        $monthlyTotal = (float) $dependents->sum('monthly_contribution_amount');
        $fundTotal = (float) $dependents->sum(fn (Member $m): float => $m->getFundBalance());
        $cashTotal = (float) $dependents->sum(fn (Member $m): float => $m->getCashBalance());

        $postedOpenCycle = (int) Contribution::query()
            ->whereIn('member_id', $dependents->pluck('id'))
            ->forPeriod($openMonth, $openYear)
            ->posted()
            ->count();

        $pendingOpenCycle = (int) Contribution::query()
            ->whereIn('member_id', $dependents->pluck('id'))
            ->forPeriod($openMonth, $openYear)
            ->pending()
            ->count();

        $delinquentCount = $dependents->filter(fn (Member $member): bool => $this->delinquency->isDelinquent($member))->count();

        $hero = $this->buildHero(
            $dependents,
            $openPeriodLabel,
            $postedOpenCycle,
            $pendingOpenCycle,
            $delinquentCount,
        );

        return [
            'hero' => $hero,
            'kpis' => $this->buildKpis(
                $dependents,
                $activeCount,
                $monthlyTotal,
                $fundTotal,
                $cashTotal,
                $postedOpenCycle,
                $pendingOpenCycle,
                $openPeriodLabel,
            ),
            'open_period' => [
                'label' => $openPeriodLabel,
                'posted' => $postedOpenCycle,
                'pending' => $pendingOpenCycle,
                'missing' => max(0, $dependents->count() - $postedOpenCycle - $pendingOpenCycle),
                'total' => $dependents->count(),
            ],
            'dependents_count' => $dependents->count(),
        ];
    }

    /**
     * @param  Collection<int, Member>  $dependents
     * @return array{tone: string, title: string, subtitle: string}
     */
    private function buildHero(
        Collection $dependents,
        string $openPeriodLabel,
        int $postedOpenCycle,
        int $pendingOpenCycle,
        int $delinquentCount,
    ): array {
        if ($delinquentCount > 0) {
            return [
                'tone' => 'danger',
                'title' => trans_choice(':count dependent needs attention|:count dependents need attention', $delinquentCount, ['count' => $delinquentCount]),
                'subtitle' => __('Review delinquent profiles and contributions for :period.', ['period' => $openPeriodLabel]),
            ];
        }

        if ($pendingOpenCycle > 0) {
            return [
                'tone' => 'amber',
                'title' => trans_choice(':count dependent pending for :period|:count dependents pending for :period', $pendingOpenCycle, [
                    'count' => $pendingOpenCycle,
                    'period' => $openPeriodLabel,
                ]),
                'subtitle' => __('Contributions awaiting fund posting.'),
            ];
        }

        if ($postedOpenCycle >= $dependents->count()) {
            return [
                'tone' => 'success',
                'title' => __('Household posted for :period', ['period' => $openPeriodLabel]),
                'subtitle' => __('All :count dependents are up to date for the open cycle.', ['count' => $dependents->count()]),
            ];
        }

        return [
            'tone' => 'sky',
            'title' => __('Household overview'),
            'subtitle' => __(':posted of :total dependents posted for :period.', [
                'posted' => $postedOpenCycle,
                'total' => $dependents->count(),
                'period' => $openPeriodLabel,
            ]),
        ];
    }

    /**
     * @param  Collection<int, Member>  $dependents
     * @return list<array{label: string, value: string, sub: string, icon: string, accent: string}>
     */
    private function buildKpis(
        Collection $dependents,
        int $activeCount,
        float $monthlyTotal,
        float $fundTotal,
        float $cashTotal,
        int $postedOpenCycle,
        int $pendingOpenCycle,
        string $openPeriodLabel,
    ): array {
        return [
            [
                'label' => __('Dependents'),
                'value' => (string) $dependents->count(),
                'sub' => trans_choice(':count active|:count active', $activeCount, ['count' => $activeCount]),
                'icon' => 'heroicon-o-user-group',
                'accent' => 'teal',
            ],
            [
                'label' => __('Monthly'),
                'value' => InsightFormatter::compactAmount($monthlyTotal),
                'sub' => InsightFormatter::money($monthlyTotal),
                'icon' => 'heroicon-o-banknotes',
                'accent' => 'emerald',
            ],
            [
                'label' => __('Cash total'),
                'value' => InsightFormatter::compactAmount($cashTotal),
                'sub' => InsightFormatter::money($cashTotal),
                'icon' => 'heroicon-o-wallet',
                'accent' => 'sky',
            ],
            [
                'label' => __('Open cycle'),
                'value' => $postedOpenCycle.'/'.$dependents->count(),
                'sub' => $pendingOpenCycle > 0
                    ? trans_choice(':count pending|:count pending', $pendingOpenCycle, ['count' => $pendingOpenCycle])
                    : $openPeriodLabel,
                'icon' => 'heroicon-o-arrow-path',
                'accent' => $pendingOpenCycle > 0 ? 'amber' : 'violet',
            ],
        ];
    }
}
