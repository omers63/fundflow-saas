<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
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

        $fundedDependents = $this->fundedDependents($dependents);
        $selfFundedCount = $dependents->count() - $fundedDependents->count();
        $contributionTracked = $this->contributionTrackedDependents($dependents, $openMonth, $openYear);
        $trackedIds = $contributionTracked->pluck('id');

        $configuredContributions = (float) $fundedDependents->sum('monthly_contribution_amount');
        $fundedWithEmiDue = $this->fundedDependentsWithEmiDue($fundedDependents, $openMonth, $openYear);
        $cycleShortfall = $this->cycles->totalDependentShortfallForParentForPeriod(
            $parent->fresh() ?? $parent,
            $openMonth,
            $openYear,
        );

        $postedOpenCycle = $trackedIds->isEmpty()
            ? 0
            : (int) Contribution::query()
                ->whereIn('member_id', $trackedIds)
                ->forPeriod($openMonth, $openYear)
                ->posted()
                ->count();

        $pendingOpenCycle = $trackedIds->isEmpty()
            ? 0
            : (int) Contribution::query()
                ->whereIn('member_id', $trackedIds)
                ->forPeriod($openMonth, $openYear)
                ->pending()
                ->count();

        $delinquentCount = $dependents->filter(fn (Member $member): bool => $this->delinquency->isDelinquent($member))->count();

        $hero = $this->buildHero(
            $dependents,
            $fundedDependents,
            $contributionTracked,
            $openPeriodLabel,
            $postedOpenCycle,
            $pendingOpenCycle,
            $cycleShortfall,
            $delinquentCount,
        );

        return [
            'hero' => $hero,
            'kpis' => $this->buildKpis(
                $dependents,
                $fundedDependents->count(),
                $selfFundedCount,
                $configuredContributions,
                $fundedWithEmiDue,
                $cycleShortfall,
                $contributionTracked->count(),
                $postedOpenCycle,
                $pendingOpenCycle,
                $openPeriodLabel,
            ),
            'open_period' => [
                'label' => $openPeriodLabel,
                'posted' => $postedOpenCycle,
                'pending' => $pendingOpenCycle,
                'missing' => max(0, $contributionTracked->count() - $postedOpenCycle - $pendingOpenCycle),
                'total' => $contributionTracked->count(),
                'funded_dependents' => $fundedDependents->count(),
                'cycle_shortfall' => $cycleShortfall,
            ],
            'dependents_count' => $dependents->count(),
        ];
    }

    /**
     * @param  Collection<int, Member>  $fundedDependents
     */
    private function fundedDependentsWithEmiDue(Collection $fundedDependents, int $month, int $year): int
    {
        $catalog = app(LoanEmiCollectionCatalogService::class);

        return $fundedDependents
            ->filter(fn (Member $member): bool => $catalog->requiredCashForMember($member, $month, $year) > 0.00001)
            ->count();
    }

    /**
     * @param  Collection<int, Member>  $dependents
     * @return Collection<int, Member>
     */
    private function fundedDependents(Collection $dependents): Collection
    {
        return $dependents->filter(fn (Member $member): bool => $member->isFundedByParent());
    }

    /**
     * Funded dependents with a contribution obligation for the period.
     *
     * @param  Collection<int, Member>  $dependents
     * @return Collection<int, Member>
     */
    private function contributionTrackedDependents(Collection $dependents, int $month, int $year): Collection
    {
        return $this->fundedDependents($dependents)->filter(
            fn (Member $member): bool => (float) $member->monthly_contribution_amount > 0
            && ! $member->isExemptFromContributions($month, $year),
        );
    }

    /**
     * @param  Collection<int, Member>  $dependents
     * @param  Collection<int, Member>  $fundedDependents
     * @param  Collection<int, Member>  $contributionTracked
     * @return array{tone: string, title: string, subtitle: string}
     */
    private function buildHero(
        Collection $dependents,
        Collection $fundedDependents,
        Collection $contributionTracked,
        string $openPeriodLabel,
        int $postedOpenCycle,
        int $pendingOpenCycle,
        float $cycleShortfall,
        int $delinquentCount,
    ): array {
        if ($delinquentCount > 0) {
            return [
                'tone' => 'danger',
                'title' => trans_choice(':count dependent needs attention|:count dependents need attention', $delinquentCount, ['count' => $delinquentCount]),
                'subtitle' => __('Review delinquent profiles and open-cycle dues for :period.', ['period' => $openPeriodLabel]),
            ];
        }

        if ($cycleShortfall > 0.00001) {
            return [
                'tone' => 'amber',
                'title' => __('Funded dependents need cash for :period', ['period' => $openPeriodLabel]),
                'subtitle' => __('Transfer :amount to cover contribution and EMI shortfalls.', [
                    'amount' => InsightFormatter::money($cycleShortfall),
                ]),
            ];
        }

        if ($pendingOpenCycle > 0) {
            return [
                'tone' => 'amber',
                'title' => trans_choice(':count funded dependent pending for :period|:count funded dependents pending for :period', $pendingOpenCycle, [
                    'count' => $pendingOpenCycle,
                    'period' => $openPeriodLabel,
                ]),
                'subtitle' => __('Contributions awaiting fund posting.'),
            ];
        }

        if ($contributionTracked->isNotEmpty() && $postedOpenCycle >= $contributionTracked->count()) {
            return [
                'tone' => 'success',
                'title' => __('Funded household posted for :period', ['period' => $openPeriodLabel]),
                'subtitle' => __('All funded dependents are up to date for the open cycle.'),
            ];
        }

        if ($fundedDependents->isEmpty()) {
            return [
                'tone' => 'sky',
                'title' => __('Self-funded household'),
                'subtitle' => __('All :count dependents manage their own contribution and EMI payments.', [
                    'count' => $dependents->count(),
                ]),
            ];
        }

        if ($contributionTracked->isEmpty()) {
            return [
                'tone' => 'success',
                'title' => __('Funded dependents ready for :period', ['period' => $openPeriodLabel]),
                'subtitle' => __('No contribution dues this cycle; EMI cash is covered.'),
            ];
        }

        return [
            'tone' => 'sky',
            'title' => __('Household overview'),
            'subtitle' => __(':posted of :total funded dependents posted for :period.', [
                'posted' => $postedOpenCycle,
                'total' => $contributionTracked->count(),
                'period' => $openPeriodLabel,
            ]),
        ];
    }

    /**
     * @return list<array{label: string, value: string, sub: string, icon: string, accent: string}>
     */
    private function buildKpis(
        Collection $dependents,
        int $fundedCount,
        int $selfFundedCount,
        float $configuredContributions,
        int $fundedWithEmiDue,
        float $cycleShortfall,
        int $contributionTrackedCount,
        int $postedOpenCycle,
        int $pendingOpenCycle,
        string $openPeriodLabel,
    ): array {
        $fundingSub = $fundedCount > 0 && $selfFundedCount > 0
            ? __(':funded funded · :self self-funded', [
                'funded' => $fundedCount,
                'self' => $selfFundedCount,
            ])
            : ($fundedCount > 0
                ? trans_choice(':count funded|:count funded', $fundedCount, ['count' => $fundedCount])
                : trans_choice(':count self-funded|:count self-funded', $selfFundedCount, ['count' => $selfFundedCount]));

        $configuredSub = match (true) {
            $fundedCount === 0 => __('No funded dependents'),
            $fundedWithEmiDue > 0 => trans_choice(
                'Recurring amount you set · EMI due for :count dependent this cycle|Recurring amount you set · EMI due for :count dependents this cycle',
                $fundedWithEmiDue,
                ['count' => $fundedWithEmiDue],
            ),
            default => __('Recurring contribution amounts you set'),
        };

        $cycleDueValue = $cycleShortfall > 0.00001
            ? InsightFormatter::compactAmount($cycleShortfall)
            : __('Ready');

        $cycleDueSub = $cycleShortfall > 0.00001
            ? __('Still to transfer · :period', ['period' => $openPeriodLabel])
            : __('Nothing left to transfer · :period', ['period' => $openPeriodLabel]);

        $missingContributions = max(0, $contributionTrackedCount - $postedOpenCycle - $pendingOpenCycle);
        $allContributionsPosted = $contributionTrackedCount > 0
            && $postedOpenCycle >= $contributionTrackedCount
            && $pendingOpenCycle === 0
            && $missingContributions === 0;

        $contributionsPosted = $this->contributionsPostedKpi(
            $contributionTrackedCount,
            $postedOpenCycle,
            $pendingOpenCycle,
            $missingContributions,
            $allContributionsPosted,
            $openPeriodLabel,
        );

        return [
            [
                'label' => __('Dependents'),
                'value' => (string) $dependents->count(),
                'sub' => $fundingSub,
                'icon' => 'heroicon-o-user-group',
                'accent' => 'teal',
            ],
            [
                'label' => __('Set contributions'),
                'value' => $fundedCount > 0
                    ? InsightFormatter::compactAmount($configuredContributions)
                    : '—',
                'sub' => $configuredSub,
                'icon' => 'heroicon-o-banknotes',
                'accent' => 'emerald',
            ],
            [
                'label' => __('Cash to transfer'),
                'value' => $fundedCount > 0 ? $cycleDueValue : '—',
                'sub' => $fundedCount > 0 ? $cycleDueSub : __('Self-funded only'),
                'icon' => 'heroicon-o-wallet',
                'accent' => $cycleShortfall > 0.00001 ? 'amber' : 'sky',
            ],
            $contributionsPosted,
        ];
    }

    /**
     * @return array{label: string, value: string, sub: string, icon: string, accent: string}
     */
    private function contributionsPostedKpi(
        int $contributionTrackedCount,
        int $postedOpenCycle,
        int $pendingOpenCycle,
        int $missingContributions,
        bool $allContributionsPosted,
        string $openPeriodLabel,
    ): array {
        if ($contributionTrackedCount === 0) {
            return [
                'label' => __('Contributions posted'),
                'value' => '—',
                'sub' => __('No contribution dues · :period', ['period' => $openPeriodLabel]),
                'icon' => 'heroicon-o-arrow-path',
                'accent' => 'violet',
            ];
        }

        $value = $allContributionsPosted
            ? __('All posted')
            : __(':posted/:total', [
                'posted' => $postedOpenCycle,
                'total' => $contributionTrackedCount,
            ]);

        $sub = match (true) {
            $allContributionsPosted => trans_choice(
                ':period · all :count funded dependent posted|:period · all :count funded dependents posted',
                $contributionTrackedCount,
                ['period' => $openPeriodLabel, 'count' => $contributionTrackedCount],
            ),
            $pendingOpenCycle > 0 => trans_choice(
                ':period · :count pending posting|:period · :count pending posting',
                $pendingOpenCycle,
                ['period' => $openPeriodLabel, 'count' => $pendingOpenCycle],
            ),
            $missingContributions > 0 => trans_choice(
                ':period · :count not started|:period · :count not started',
                $missingContributions,
                ['period' => $openPeriodLabel, 'count' => $missingContributions],
            ),
            default => __(':period · :posted of :total funded dependents posted', [
                'period' => $openPeriodLabel,
                'posted' => $postedOpenCycle,
                'total' => $contributionTrackedCount,
            ]),
        };

        return [
            'label' => __('Contributions posted'),
            'value' => $value,
            'sub' => $sub,
            'icon' => 'heroicon-o-arrow-path',
            'accent' => $allContributionsPosted ? 'emerald' : ($pendingOpenCycle > 0 || $missingContributions > 0 ? 'amber' : 'violet'),
        ];
    }
}
