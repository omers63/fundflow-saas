<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
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

        $parent->loadMissing(['loans.installments']);

        $dependents = $parent->dependents()
            ->with(['cashAccount', 'fundAccount', 'loans.installments'])
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
        $catalog = app(LoanEmiCollectionCatalogService::class);
        $household = collect([$parent])->concat($fundedDependents);
        $contributionSetMembers = $this->contributionSetMembers($household, $openMonth, $openYear);
        $emiSetMembers = $this->emiSetMembers($household, $openMonth, $openYear, $catalog);
        $configuredContributions = (float) $contributionSetMembers->sum('monthly_contribution_amount');
        $configuredEmi = (float) $emiSetMembers->sum(
            fn (Member $member): float => $this->scheduledEmiAmountForMember($member, $openMonth, $openYear, $catalog),
        );
        $emiPosted = $this->emiPostedState($household, $openMonth, $openYear, $openPeriodLabel, $catalog);
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

        $contributionPosted = $this->contributionPostedState(
            $parent,
            $fundedDependents,
            $contributionTracked->count(),
            $postedOpenCycle,
            $pendingOpenCycle,
            $openMonth,
            $openYear,
            $openPeriodLabel,
        );

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
                $contributionSetMembers,
                $configuredEmi,
                $emiSetMembers,
                $parent->id,
                $cycleShortfall,
                $contributionPosted,
                $emiPosted,
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
     * Parent plus sponsored dependents who contribute this cycle (not EMI-exempt).
     *
     * @param  Collection<int, Member>  $household
     * @return Collection<int, Member>
     */
    private function contributionSetMembers(Collection $household, int $month, int $year): Collection
    {
        return $household->filter(
            fn (Member $member): bool => (float) $member->monthly_contribution_amount > 0
            && ! $member->isExemptFromContributions($month, $year),
        );
    }

    /**
     * Parent plus sponsored dependents with an EMI due this cycle.
     *
     * @param  Collection<int, Member>  $household
     * @return Collection<int, Member>
     */
    private function emiSetMembers(
        Collection $household,
        int $month,
        int $year,
        LoanEmiCollectionCatalogService $catalog,
    ): Collection {
        return $household->filter(
            fn (Member $member): bool => $member->isInActiveLoanContributionExemptCycle($month, $year)
            || $catalog->installmentsDueInPeriodForMember($member, $month, $year)->isNotEmpty(),
        );
    }

    private function scheduledEmiAmountForMember(
        Member $member,
        int $month,
        int $year,
        LoanEmiCollectionCatalogService $catalog,
    ): float {
        $due = $catalog->installmentsDueInPeriodForMember($member, $month, $year);

        if ($due->isNotEmpty()) {
            return (float) $due->sum('amount');
        }

        $loan = $member->relationLoaded('loans')
            ? $member->loans->first(
                fn (Loan $loan): bool => in_array((string) $loan->status, ['active', 'transferred'], true),
            )
            : $member->loans()->whereIn('status', ['active', 'transferred'])->first();

        return (float) ($loan?->monthly_repayment ?? 0);
    }

    /**
     * @param  Collection<int, Member>  $members
     */
    private function householdSetSubtitle(
        Collection $members,
        int $parentId,
        string $empty,
        string $youOnly,
    ): string {
        $includesParent = $members->contains(fn (Member $member): bool => (int) $member->id === $parentId);
        $dependentCount = $members->filter(fn (Member $member): bool => (int) $member->id !== $parentId)->count();

        if ($includesParent && $dependentCount === 0) {
            return $youOnly;
        }

        if ($includesParent) {
            return trans_choice(
                'You and :count funded dependent|You and :count funded dependents',
                $dependentCount,
                ['count' => $dependentCount],
            );
        }

        if ($dependentCount > 0) {
            return trans_choice(
                ':count funded dependent|:count funded dependents',
                $dependentCount,
                ['count' => $dependentCount],
            );
        }

        return $empty;
    }

    /**
     * @param  Collection<int, Member>  $fundedDependents
     * @return array{tracked: int, posted: int, pending: int, missing: int, period_label: string, is_advance: bool, is_last: bool}
     */
    private function contributionPostedState(
        Member $parent,
        Collection $fundedDependents,
        int $openTracked,
        int $postedOpenCycle,
        int $pendingOpenCycle,
        int $openMonth,
        int $openYear,
        string $openPeriodLabel,
    ): array {
        $missingOpen = max(0, $openTracked - $postedOpenCycle - $pendingOpenCycle);
        $householdIds = collect([$parent->id])
            ->concat($fundedDependents->pluck('id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($pendingOpenCycle === 0 && $missingOpen === 0) {
            $advance = $this->latestPostedContributionPeriod($householdIds, $openMonth, $openYear);

            if ($advance !== null) {
                return $this->contributionPostedSnapshotForPeriod($householdIds, $advance[0], $advance[1], true, false);
            }

            if ($openTracked === 0) {
                $last = $this->latestPostedContributionPeriod($householdIds);

                if ($last !== null) {
                    [$month, $year] = $last;
                    $isAdvance = (($year * 12) + $month) > (($openYear * 12) + $openMonth);
                    $isLast = (($year * 12) + $month) < (($openYear * 12) + $openMonth);

                    return $this->contributionPostedSnapshotForPeriod($householdIds, $month, $year, $isAdvance, $isLast);
                }
            }
        }

        return [
            'tracked' => $openTracked,
            'posted' => $postedOpenCycle,
            'pending' => $pendingOpenCycle,
            'missing' => $missingOpen,
            'period_label' => $openPeriodLabel,
            'is_advance' => false,
            'is_last' => false,
        ];
    }

    /**
     * @param  list<int>  $memberIds
     * @return array{tracked: int, posted: int, pending: int, missing: int, period_label: string, is_advance: bool, is_last: bool}
     */
    private function contributionPostedSnapshotForPeriod(
        array $memberIds,
        int $month,
        int $year,
        bool $isAdvance,
        bool $isLast,
    ): array {
        $rows = Contribution::query()
            ->whereIn('member_id', $memberIds)
            ->forPeriod($month, $year)
            ->get(['member_id', 'status']);

        $posted = $rows->where('status', 'posted')->pluck('member_id')->unique()->count();
        $pending = $rows->where('status', 'pending')->pluck('member_id')->unique()->count();
        $tracked = $rows->pluck('member_id')->unique()->count();

        return [
            'tracked' => $tracked,
            'posted' => $posted,
            'pending' => $pending,
            'missing' => 0,
            'period_label' => $this->cycles->periodLabel($month, $year),
            'is_advance' => $isAdvance,
            'is_last' => $isLast,
        ];
    }

    /**
     * @param  list<int>  $memberIds
     * @return array{0: int, 1: int}|null
     */
    private function latestPostedContributionPeriod(
        array $memberIds,
        ?int $afterMonth = null,
        ?int $afterYear = null,
    ): ?array {
        if ($memberIds === []) {
            return null;
        }

        $query = Contribution::query()
            ->whereIn('member_id', $memberIds)
            ->posted();

        if ($afterMonth !== null && $afterYear !== null) {
            $query->where('period', '>', Contribution::periodDate($afterMonth, $afterYear));
        }

        $period = $query->max('period');

        if ($period === null || $period === '') {
            return null;
        }

        return Contribution::monthYearFromPeriod($period);
    }

    /**
     * @param  Collection<int, Member>  $household
     * @return array{tracked: int, posted: int, unpaid: int, missing: int, period_label: string, is_advance: bool}
     */
    private function emiPostedState(
        Collection $household,
        int $openMonth,
        int $openYear,
        string $openPeriodLabel,
        LoanEmiCollectionCatalogService $catalog,
    ): array {
        $openMembers = $this->emiSetMembers($household, $openMonth, $openYear, $catalog);
        $openCounts = $this->emiPostedCounts($openMembers, $openMonth, $openYear, $catalog);
        $advancePeriod = $openCounts['unpaid'] === 0
            ? $this->latestPaidEmiPeriodAfter($household, $openMonth, $openYear)
            : null;

        if ($advancePeriod !== null) {
            [$month, $year] = $advancePeriod;
            $members = $this->membersWithEmiInPeriod($household, $month, $year, $catalog);
            $counts = $this->emiPostedCounts($members, $month, $year, $catalog);

            return [
                ...$counts,
                'tracked' => $members->count(),
                'period_label' => $this->cycles->periodLabel($month, $year),
                'is_advance' => true,
            ];
        }

        return [
            ...$openCounts,
            'tracked' => $openMembers->count(),
            'period_label' => $openPeriodLabel,
            'is_advance' => false,
        ];
    }

    /**
     * @param  Collection<int, Member>  $household
     * @return Collection<int, Member>
     */
    private function membersWithEmiInPeriod(
        Collection $household,
        int $month,
        int $year,
        LoanEmiCollectionCatalogService $catalog,
    ): Collection {
        return $household->filter(
            fn (Member $member): bool => $catalog->installmentsDueInPeriodForMember($member, $month, $year)->isNotEmpty(),
        );
    }

    /**
     * @param  Collection<int, Member>  $members
     * @return array{posted: int, unpaid: int, missing: int}
     */
    private function emiPostedCounts(
        Collection $members,
        int $month,
        int $year,
        LoanEmiCollectionCatalogService $catalog,
    ): array {
        $posted = 0;
        $unpaid = 0;
        $missing = 0;

        foreach ($members as $member) {
            $due = $catalog->installmentsDueInPeriodForMember($member, $month, $year);

            if ($due->isEmpty()) {
                $missing++;

                continue;
            }

            if ($due->every(fn (LoanInstallment $installment): bool => $installment->status === 'paid')) {
                $posted++;
            } else {
                $unpaid++;
            }
        }

        return [
            'posted' => $posted,
            'unpaid' => $unpaid,
            'missing' => $missing,
        ];
    }

    /**
     * @param  Collection<int, Member>  $household
     * @return array{0: int, 1: int}|null
     */
    private function latestPaidEmiPeriodAfter(Collection $household, int $openMonth, int $openYear): ?array
    {
        $openIndex = ($openYear * 12) + $openMonth;
        $best = null;
        $bestIndex = $openIndex;

        foreach ($household as $member) {
            $loans = $member->relationLoaded('loans')
                ? $member->loans
                : $member->loans()->with('installments')->get();

            foreach ($loans as $loan) {
                if (! in_array((string) $loan->status, ['active', 'transferred'], true)) {
                    continue;
                }

                $installments = $loan->relationLoaded('installments')
                    ? $loan->installments
                    : $loan->installments()->get();

                foreach ($installments as $installment) {
                    if ($installment->status !== 'paid' || $installment->due_date === null) {
                        continue;
                    }

                    [$month, $year] = $this->cycles->cyclePeriodForDueDate($installment->due_date);
                    $index = ($year * 12) + $month;

                    if ($index > $bestIndex) {
                        $bestIndex = $index;
                        $best = [$month, $year];
                    }
                }
            }
        }

        return $best;
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
     * @param  Collection<int, Member>  $contributionSetMembers
     * @param  Collection<int, Member>  $emiSetMembers
     * @param  array{tracked: int, posted: int, pending: int, missing: int, period_label: string, is_advance: bool, is_last: bool}  $contributionPosted
     * @param  array{tracked: int, posted: int, unpaid: int, missing: int, period_label: string, is_advance: bool}  $emiPosted
     * @return list<array{label: string, value: string, sub: string, icon: string, accent: string}>
     */
    private function buildKpis(
        Collection $dependents,
        int $fundedCount,
        int $selfFundedCount,
        float $configuredContributions,
        Collection $contributionSetMembers,
        float $configuredEmi,
        Collection $emiSetMembers,
        int $parentId,
        float $cycleShortfall,
        array $contributionPosted,
        array $emiPosted,
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

        $cycleDueValue = $cycleShortfall > 0.00001
            ? InsightFormatter::compactAmount($cycleShortfall)
            : __('Ready');

        $cycleDueSub = $cycleShortfall > 0.00001
            ? __('Still to transfer · :period', ['period' => $openPeriodLabel])
            : __('Nothing left to transfer · :period', ['period' => $openPeriodLabel]);

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
                'value' => $configuredContributions > 0.00001
                    ? InsightFormatter::compactAmount($configuredContributions)
                    : '—',
                'sub' => $this->householdSetSubtitle(
                    $contributionSetMembers,
                    $parentId,
                    __('No contribution dues this cycle'),
                    __('Your standing amount'),
                ),
                'icon' => 'heroicon-o-banknotes',
                'accent' => 'emerald',
            ],
            [
                'label' => __('Set EMI repayments'),
                'value' => $configuredEmi > 0.00001
                    ? InsightFormatter::compactAmount($configuredEmi)
                    : '—',
                'sub' => $this->householdSetSubtitle(
                    $emiSetMembers,
                    $parentId,
                    __('No EMI dues this cycle'),
                    __('Your scheduled EMI'),
                ),
                'icon' => 'heroicon-o-calendar-days',
                'accent' => 'indigo',
            ],
            [
                'label' => __('Cash to transfer'),
                'value' => $fundedCount > 0 ? $cycleDueValue : '—',
                'sub' => $fundedCount > 0 ? $cycleDueSub : __('Self-funded only'),
                'icon' => 'heroicon-o-wallet',
                'accent' => $cycleShortfall > 0.00001 ? 'amber' : 'sky',
            ],
            $this->contributionsPostedKpi($contributionPosted),
            $this->emiPostedKpi($emiPosted),
        ];
    }

    /**
     * @param  array{tracked: int, posted: int, pending: int, missing: int, period_label: string, is_advance: bool, is_last: bool}  $contributionPosted
     * @return array{label: string, value: string, sub: string, icon: string, accent: string}
     */
    private function contributionsPostedKpi(array $contributionPosted): array
    {
        $tracked = $contributionPosted['tracked'];
        $posted = $contributionPosted['posted'];
        $pending = $contributionPosted['pending'];
        $missing = $contributionPosted['missing'];
        $periodLabel = $contributionPosted['period_label'];
        $allPosted = $tracked > 0 && $posted >= $tracked && $pending === 0 && $missing === 0;

        if ($tracked === 0) {
            return [
                'label' => __('Contributions posted'),
                'value' => '—',
                'sub' => __('No contribution dues · :period', ['period' => $periodLabel]),
                'icon' => 'heroicon-o-arrow-path',
                'accent' => 'violet',
            ];
        }

        $value = $allPosted
            ? __('All posted')
            : __(':posted/:total', [
                'posted' => $posted,
                'total' => $tracked,
            ]);

        $sub = match (true) {
            $allPosted && $contributionPosted['is_advance'] => __(':period · paid in advance', ['period' => $periodLabel]),
            $allPosted && $contributionPosted['is_last'] => __(':period · last contributed', ['period' => $periodLabel]),
            $allPosted => trans_choice(
                ':period · all :count funded dependent posted|:period · all :count funded dependents posted',
                $tracked,
                ['period' => $periodLabel, 'count' => $tracked],
            ),
            $pending > 0 => trans_choice(
                ':period · :count pending posting|:period · :count pending posting',
                $pending,
                ['period' => $periodLabel, 'count' => $pending],
            ),
            $missing > 0 => trans_choice(
                ':period · :count not started|:period · :count not started',
                $missing,
                ['period' => $periodLabel, 'count' => $missing],
            ),
            default => __(':period · :posted of :total funded dependents posted', [
                'period' => $periodLabel,
                'posted' => $posted,
                'total' => $tracked,
            ]),
        };

        return [
            'label' => __('Contributions posted'),
            'value' => $value,
            'sub' => $sub,
            'icon' => 'heroicon-o-arrow-path',
            'accent' => $allPosted ? 'emerald' : ($pending > 0 || $missing > 0 ? 'amber' : 'violet'),
        ];
    }

    /**
     * @param  array{tracked: int, posted: int, unpaid: int, missing: int, period_label: string, is_advance: bool}  $emiPosted
     * @return array{label: string, value: string, sub: string, icon: string, accent: string}
     */
    private function emiPostedKpi(array $emiPosted): array
    {
        $tracked = $emiPosted['tracked'];
        $posted = $emiPosted['posted'];
        $unpaid = $emiPosted['unpaid'];
        $missing = $emiPosted['missing'];
        $periodLabel = $emiPosted['period_label'];
        $allPosted = $tracked > 0 && $posted >= $tracked && $unpaid === 0 && $missing === 0;

        if ($tracked === 0) {
            return [
                'label' => __('EMI repayments posted'),
                'value' => '—',
                'sub' => __('No EMI dues · :period', ['period' => $periodLabel]),
                'icon' => 'heroicon-o-check-circle',
                'accent' => 'indigo',
            ];
        }

        $value = $allPosted
            ? __('All posted')
            : __(':posted/:total', [
                'posted' => $posted,
                'total' => $tracked,
            ]);

        $sub = match (true) {
            $allPosted && $emiPosted['is_advance'] => __(':period · paid in advance', ['period' => $periodLabel]),
            $allPosted => trans_choice(
                ':period · all :count EMI posted|:period · all :count EMIs posted',
                $tracked,
                ['period' => $periodLabel, 'count' => $tracked],
            ),
            $unpaid > 0 => trans_choice(
                ':period · :count unpaid EMI|:period · :count unpaid EMIs',
                $unpaid,
                ['period' => $periodLabel, 'count' => $unpaid],
            ),
            $missing > 0 => trans_choice(
                ':period · :count not started|:period · :count not started',
                $missing,
                ['period' => $periodLabel, 'count' => $missing],
            ),
            default => __(':period · :posted of :total EMIs posted', [
                'period' => $periodLabel,
                'posted' => $posted,
                'total' => $tracked,
            ]),
        };

        return [
            'label' => __('EMI repayments posted'),
            'value' => $value,
            'sub' => $sub,
            'icon' => 'heroicon-o-check-circle',
            'accent' => $allPosted ? 'emerald' : ($unpaid > 0 || $missing > 0 ? 'amber' : 'indigo'),
        ];
    }
}
