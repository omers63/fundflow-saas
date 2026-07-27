<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Support\DelinquencyTabRegistry;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\BusinessDay;
use App\Support\CollectionInsightsCache;
use App\Support\Insights\InsightKpi;
use InvalidArgumentException;

final class ContributionInsightsService
{
    public function __construct(
        protected ContributionCycleService $cycles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forContext(string $context, ?string $cycleKey = null): array
    {
        $resolvedCycle = $cycleKey;

        if (! filled($resolvedCycle)) {
            $resolvedCycle = ContributionResource::resolveListCycleKey() ?? '';
        }

        return CollectionInsightsCache::remember(
            CollectionInsightsCache::DOMAIN_CONTRIBUTIONS,
            "{$context}:{$resolvedCycle}",
            fn (): array => match ($context) {
                'collect' => $this->collectSnapshot($cycleKey),
                'collected' => $this->collectedSnapshot($cycleKey),
                'arrears' => $this->arrearsSnapshot($cycleKey),
                'contributions', 'ledger' => $this->ledgerSnapshot($cycleKey),
                default => $this->ledgerSnapshot($cycleKey),
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?string $cycleKey = null): array
    {
        return $this->ledgerSnapshot($cycleKey);
    }

    /**
     * @return array{0: int, 1: int, 2: string, 3: bool}
     */
    private function resolvePeriod(?string $cycleKey = null): array
    {
        if (filled($cycleKey)) {
            try {
                [$month, $year] = $this->cycles->parseContributionCycleKey($cycleKey);
            } catch (InvalidArgumentException) {
                [$month, $year] = ContributionResource::resolveListCycle();
            }
        } else {
            [$month, $year] = ContributionResource::resolveListCycle();
        }

        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        $isOpen = $month === $openMonth && $year === $openYear;
        $periodLabel = $this->cycles->periodLabel($month, $year);

        return [$month, $year, $periodLabel, $isOpen];
    }

    /**
     * Outstanding unpaid members for the selected cycle (To collect / cycle Arrears).
     */
    private function outstandingCount(int $month, int $year): int
    {
        return ContributionResource::pendingCountForPeriod($month, $year);
    }

    /**
     * Posted contribution rows that belong on the cycle Collected workspace.
     */
    private function collectedCount(int $month, int $year): int
    {
        return $this->cycles->postedContributionCount($month, $year);
    }

    private function outstandingSegmentUrl(bool $isOpen): string
    {
        return ContributionResource::listTabUrl($isOpen ? 'collect' : 'arrears');
    }

    /**
     * @return array<string, mixed>
     */
    public function collectSnapshot(?string $cycleKey = null): array
    {
        [$month, $year, $periodLabel, $isOpen] = $this->resolvePeriod($cycleKey);
        $currency = Setting::get('general', 'currency', 'USD');

        $missingMembers = $this->outstandingCount($month, $year);
        $postedMembers = $this->collectedCount($month, $year);
        $pendingRows = Contribution::query()
            ->forPeriod($month, $year)
            ->pending()
            ->count();
        $lateRows = Contribution::query()
            ->forPeriod($month, $year)
            ->pending()
            ->where('is_late', true)
            ->count();

        $denominator = $postedMembers + $missingMembers;
        $collectionRate = $denominator > 0
            ? (int) round(($postedMembers / $denominator) * 100)
            : 0;

        $outstandingUrl = $this->outstandingSegmentUrl($isOpen);
        $collectedUrl = ContributionResource::listTabUrl('collected');
        $periodSub = $isOpen ? __('Open period') : $periodLabel;
        $arrearsPeriods = ContributionResource::contributionArrearsPeriodCount();

        return [
            'currency' => $currency,
            'open_period' => [
                'label' => $periodLabel,
                'collection_rate' => $collectionRate,
                'missing_members' => $missingMembers,
            ],
            'hero' => [
                'tone' => $missingMembers > 0 ? 'amber' : 'success',
                'title' => $missingMembers > 0
                    ? ($isOpen
                        ? __('Open period collection in progress')
                        : __('Collection in progress for :period', ['period' => $periodLabel]))
                    : ($isOpen
                        ? __('Open period fully collected')
                        : __('Fully collected for :period', ['period' => $periodLabel])),
                'subtitle' => $missingMembers > 0
                    ? trans_choice(
                        ':count member still to collect for :period|:count members still to collect for :period',
                        $missingMembers,
                        ['count' => $missingMembers, 'period' => $periodLabel],
                    )
                    : __('All members have posted for :period.', ['period' => $periodLabel]),
                'cta_label' => $missingMembers > 0 ? ($isOpen ? __('To collect') : __('Arrears')) : null,
                'cta_url' => $missingMembers > 0 ? $outstandingUrl : null,
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'missing', 'label' => $isOpen ? __('To collect') : __('Arrears'), 'value' => (string) $missingMembers, 'sub' => __('Members'), 'icon' => 'heroicon-o-user-group', 'accent' => 'amber', 'active' => $missingMembers > 0],
                ['key' => 'posted', 'label' => __('Collected'), 'value' => (string) $postedMembers, 'sub' => $periodLabel, 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true],
                ['key' => 'pending', 'label' => __('Pending rows'), 'value' => (string) $pendingRows, 'sub' => __('Ledger'), 'icon' => 'heroicon-o-clock', 'accent' => 'sky', 'active' => $pendingRows > 0],
                ['key' => 'rate', 'label' => __('Collection'), 'value' => $collectionRate.'%', 'sub' => $periodSub, 'icon' => 'heroicon-o-chart-pie', 'accent' => 'violet', 'active' => true],
                ['key' => 'late', 'label' => __('Late'), 'value' => (string) $lateRows, 'sub' => $periodSub, 'icon' => 'heroicon-o-exclamation-triangle', 'accent' => 'rose', 'active' => $lateRows > 0],
                ['key' => 'arrears', 'label' => __('Arrears'), 'value' => (string) $arrearsPeriods, 'sub' => __('Past periods'), 'icon' => 'heroicon-o-banknotes', 'accent' => 'rose', 'active' => $arrearsPeriods > 0],
            ], [
                'missing' => $outstandingUrl,
                'posted' => $collectedUrl,
                'pending' => ContributionResource::listUrl('contributions', ['status' => ['value' => 'pending']]),
                'rate' => $outstandingUrl,
                'late' => ContributionResource::listUrl('contributions', ['status' => ['value' => 'pending']]),
                'arrears' => ContributionResource::listTabUrl('arrears'),
            ]),
            'pipeline' => [
                'missing_open_period' => $missingMembers,
                'posted_open_period' => $postedMembers,
                'pending_open_period' => $pendingRows,
                'arrears_periods' => $arrearsPeriods,
                'collect_url' => $outstandingUrl,
                'collected_url' => $collectedUrl,
                'arrears_url' => ContributionResource::listTabUrl('arrears'),
                'ledger_pending_url' => ContributionResource::listUrl('contributions', ['status' => ['value' => 'pending']]),
            ],
            'collection_amounts' => $this->contributionCycleCollectionAmounts($month, $year),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collectedSnapshot(?string $cycleKey = null): array
    {
        [$month, $year, $periodLabel, $isOpen] = $this->resolvePeriod($cycleKey);
        $currency = Setting::get('general', 'currency', 'USD');

        $postedMembers = $this->collectedCount($month, $year);
        $postedAmount = (float) $this->cycles->postedContributionsQueryForPeriod($month, $year)
            ->sum('amount');
        $missingMembers = $this->outstandingCount($month, $year);
        $outstandingUrl = $this->outstandingSegmentUrl($isOpen);
        $collectedUrl = ContributionResource::listTabUrl('collected');

        return [
            'currency' => $currency,
            'open_period' => ['label' => $periodLabel],
            'hero' => [
                'tone' => 'success',
                'title' => __('Collected for :period', ['period' => $periodLabel]),
                'subtitle' => trans_choice(
                    ':count posted contribution row|:count posted contribution rows',
                    $postedMembers,
                    ['count' => $postedMembers],
                ).($missingMembers > 0
                    ? ' · '.trans_choice(
                        $isOpen
                        ? ':count member still on To collect|:count members still on To collect'
                        : ':count member still in Arrears|:count members still in Arrears',
                        $missingMembers,
                        ['count' => $missingMembers],
                    )
                    : ''),
                'cta_label' => $missingMembers > 0 ? ($isOpen ? __('To collect') : __('Arrears')) : null,
                'cta_url' => $missingMembers > 0 ? $outstandingUrl : null,
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'posted', 'label' => __('Collected'), 'value' => (string) $postedMembers, 'sub' => $periodLabel, 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true],
                ['key' => 'amount', 'label' => __('Amount'), 'value' => $postedAmount, 'currency' => $currency, 'value_is_amount' => true, 'value_precision' => 0, 'sub' => $periodLabel, 'icon' => 'heroicon-o-currency-dollar', 'accent' => 'teal', 'active' => $postedAmount > 0],
                ['key' => 'contributions', 'label' => __('Contributions'), 'value' => (string) Contribution::query()->posted()->count(), 'sub' => __('All time'), 'icon' => 'heroicon-o-book-open', 'accent' => 'sky', 'active' => true],
                ['key' => 'collect', 'label' => $isOpen ? __('To collect') : __('Arrears'), 'value' => (string) $missingMembers, 'sub' => __('Members'), 'icon' => 'heroicon-o-arrow-down-tray', 'accent' => 'violet', 'active' => $missingMembers > 0],
                ['key' => 'arrears', 'label' => __('Arrears'), 'value' => (string) ContributionResource::contributionArrearsPeriodCount(), 'sub' => __('Past periods'), 'icon' => 'heroicon-o-banknotes', 'accent' => 'rose', 'active' => true],
            ], [
                'posted' => $collectedUrl,
                'amount' => $collectedUrl,
                'contributions' => ContributionResource::listUrl('contributions'),
                'collect' => $outstandingUrl,
                'arrears' => ContributionResource::listTabUrl('arrears'),
            ]),
            'pipeline' => [
                'posted_open_period' => $postedMembers,
                'missing_open_period' => $missingMembers,
                'collected_url' => $collectedUrl,
                'collect_url' => $outstandingUrl,
            ],
            'collection_amounts' => $this->contributionCycleCollectionAmounts($month, $year),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function arrearsSnapshot(?string $cycleKey = null): array
    {
        [$month, $year, $periodLabel, $isOpen] = $this->resolvePeriod($cycleKey);
        $currency = Setting::get('general', 'currency', 'USD');
        $onCycleWorkspace = ContributionResource::resolvePrimaryTab() === 'cycle';

        if ($onCycleWorkspace) {
            $missingMembers = $this->outstandingCount($month, $year);
            $postedMembers = $this->collectedCount($month, $year);
            $outstandingUrl = $this->outstandingSegmentUrl($isOpen);
            $collectedUrl = ContributionResource::listTabUrl('collected');
            $periodSub = $isOpen ? __('Open period') : $periodLabel;

            return [
                'currency' => $currency,
                'open_period' => ['label' => $periodLabel],
                'hero' => [
                    'tone' => $missingMembers > 0 ? 'danger' : 'success',
                    'title' => $missingMembers > 0
                        ? __('Arrears for :period', ['period' => $periodLabel])
                        : __('No arrears for :period', ['period' => $periodLabel]),
                    'subtitle' => $missingMembers > 0
                        ? trans_choice(
                            ':count member still to collect for :period|:count members still to collect for :period',
                            $missingMembers,
                            ['count' => $missingMembers, 'period' => $periodLabel],
                        )
                        : __('All liable members have posted for :period.', ['period' => $periodLabel]),
                    'cta_label' => $missingMembers > 0 ? __('Arrears') : null,
                    'cta_url' => $missingMembers > 0 ? $outstandingUrl : null,
                ],
                'kpis' => InsightKpi::linkMany([
                    ['key' => 'arrears', 'label' => __('Arrears'), 'value' => (string) $missingMembers, 'sub' => $periodLabel, 'icon' => 'heroicon-o-banknotes', 'accent' => 'rose', 'active' => $missingMembers > 0],
                    ['key' => 'posted', 'label' => __('Collected'), 'value' => (string) $postedMembers, 'sub' => $periodLabel, 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true],
                    ['key' => 'rate', 'label' => __('Collection'), 'value' => (($postedMembers + $missingMembers) > 0 ? (int) round(($postedMembers / ($postedMembers + $missingMembers)) * 100) : 0).'%', 'sub' => $periodSub, 'icon' => 'heroicon-o-chart-pie', 'accent' => 'violet', 'active' => true],
                    ['key' => 'ledger', 'label' => __('Ledger arrears'), 'value' => (string) ContributionResource::contributionArrearsPeriodCount(), 'sub' => __('Past periods'), 'icon' => 'heroicon-o-book-open', 'accent' => 'amber', 'active' => true],
                ], [
                    'arrears' => $outstandingUrl,
                    'posted' => $collectedUrl,
                    'rate' => $outstandingUrl,
                    'ledger' => ContributionResource::listUrl('ledger', view: 'arrears'),
                ]),
                'pipeline' => [
                    'arrears_periods' => $missingMembers,
                    'arrears_members' => $missingMembers,
                    'collect_url' => $outstandingUrl,
                    'collected_url' => $collectedUrl,
                    'arrears_url' => $outstandingUrl,
                ],
                'collection_amounts' => $this->contributionCycleCollectionAmounts($month, $year),
            ];
        }

        $delinquency = app(LoanDelinquencyService::class);
        $live = $isOpen;
        $arrearsPeriods = ContributionResource::contributionArrearsPeriodCount();
        $arrearsMembers = $delinquency->countContributionArrearsMembers($month, $year, $live);
        $delinquentMembers = count($delinquency->delinquentMemberIds());
        $overdueInstallments = (int) LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereHas('loan', fn ($query) => $query->where('status', 'active'))
            ->count();
        $guarantorAtRisk = $delinquency->loansAtGuarantorRiskCount();

        $arrearsUrl = ContributionResource::listUrl('ledger', view: 'arrears');

        return [
            'currency' => $currency,
            'open_period' => ['label' => $periodLabel],
            'hero' => [
                'tone' => $arrearsPeriods > 0 ? 'danger' : 'success',
                'title' => $arrearsPeriods > 0
                    ? __('Contribution arrears need attention')
                    : __('No contribution arrears'),
                'subtitle' => $arrearsPeriods > 0
                    ? trans_choice(
                        ':count unposted period across :members member(s)|:count unposted periods across :members member(s)',
                        $arrearsPeriods,
                        ['count' => $arrearsPeriods, 'members' => $arrearsMembers],
                    )
                    : __('All contribution periods are current for active members.'),
                'cta_label' => $arrearsPeriods > 0 ? __('Review arrears') : null,
                'cta_url' => $arrearsPeriods > 0 ? $arrearsUrl : null,
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'arrears', 'label' => __('Arrears'), 'value' => (string) $arrearsPeriods, 'sub' => __('Periods'), 'icon' => 'heroicon-o-banknotes', 'accent' => 'rose', 'active' => $arrearsPeriods > 0, 'value_class' => $arrearsPeriods > 0 ? 'text-rose-600 dark:text-rose-400' : null],
                ['key' => 'members', 'label' => __('Members'), 'value' => (string) $arrearsMembers, 'sub' => __('With arrears'), 'icon' => 'heroicon-o-user-group', 'accent' => 'amber', 'active' => $arrearsMembers > 0],
                ['key' => 'delinquent', 'label' => __('Delinquent'), 'value' => (string) $delinquentMembers, 'sub' => __('Members'), 'icon' => 'heroicon-o-user-minus', 'accent' => 'violet', 'active' => $delinquentMembers > 0],
                ['key' => 'collect', 'label' => __('To collect'), 'value' => (string) ContributionResource::openCyclePendingCount(), 'sub' => __('Open period'), 'icon' => 'heroicon-o-arrow-down-tray', 'accent' => 'sky', 'active' => true],
                ['key' => 'overdue', 'label' => __('Overdue EMIs'), 'value' => (string) $overdueInstallments, 'sub' => __('Loans'), 'icon' => 'heroicon-o-calendar-days', 'accent' => 'rose', 'active' => $overdueInstallments > 0],
                ['key' => 'guarantor', 'label' => __('Guarantor'), 'value' => (string) $guarantorAtRisk, 'sub' => __('Exposure'), 'icon' => 'heroicon-o-shield-exclamation', 'accent' => 'amber', 'active' => $guarantorAtRisk > 0],
            ], [
                'arrears' => $arrearsUrl,
                'members' => $arrearsUrl,
                'delinquent' => MemberResource::listTabUrl('delinquent'),
                'collect' => ContributionResource::listTabUrl('collect'),
                'overdue' => DelinquencyTabRegistry::url('overdue'),
                'guarantor' => DelinquencyTabRegistry::url('guarantor'),
            ]),
            'pipeline' => [
                'arrears_periods' => $arrearsPeriods,
                'arrears_members' => $arrearsMembers,
                'delinquent_members' => $delinquentMembers,
                'overdue_installments' => $overdueInstallments,
                'guarantor_at_risk' => $guarantorAtRisk,
                'arrears_url' => $arrearsUrl,
                'collect_url' => ContributionResource::listTabUrl('collect'),
                'delinquent_url' => MemberResource::listTabUrl('delinquent'),
                'overdue_url' => DelinquencyTabRegistry::url('overdue'),
                'guarantor_url' => DelinquencyTabRegistry::url('guarantor'),
            ],
            'collection_amounts' => $this->contributionCycleCollectionAmounts($month, $year),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ledgerSnapshot(?string $cycleKey = null): array
    {
        $now = BusinessDay::now();
        [$month, $year, $periodLabel] = $this->resolvePeriod($cycleKey);

        $pending = Contribution::query()->where('status', 'pending')->count();
        $posted = Contribution::query()->where('status', 'posted')->count();
        $failed = Contribution::query()->where('status', 'failed')->count();
        $total = $pending + $posted + $failed;

        $pendingAmountTotal = (float) Contribution::query()
            ->where('status', 'pending')
            ->sum('amount');

        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $postedAmountThisMonth = (float) Contribution::query()
            ->where('status', 'posted')
            ->whereBetween('posted_at', [$monthStart, $monthEnd])
            ->sum('amount');

        $postedThisMonth = Contribution::query()
            ->where('status', 'posted')
            ->whereBetween('posted_at', [$monthStart, $monthEnd])
            ->count();

        $lateCount = Contribution::query()
            ->where('status', 'pending')
            ->where('is_late', true)
            ->count();

        $missingMembers = $this->outstandingCount($month, $year);
        $postedMembers = $this->collectedCount($month, $year);
        $pendingRows = Contribution::query()
            ->forPeriod($month, $year)
            ->pending()
            ->count();

        $denominator = $postedMembers + $missingMembers;
        $collectionRate = $denominator > 0
            ? (int) round(($postedMembers / $denominator) * 100)
            : 0;

        $currency = Setting::get('general', 'currency', 'USD');
        $contributionsUrl = ContributionResource::listUrl('contributions');
        $arrearsPeriods = ContributionResource::contributionArrearsPeriodCount();

        return [
            'total' => $total,
            'pending' => $pending,
            'posted' => $posted,
            'failed' => $failed,
            'pending_amount_total' => $pendingAmountTotal,
            'posted_amount_this_month' => $postedAmountThisMonth,
            'posted_this_month' => $postedThisMonth,
            'late_count' => $lateCount,
            'open_period' => [
                'label' => $periodLabel,
                'month' => $month,
                'year' => $year,
                'is_late' => $this->cycles->isLate($month, $year),
                'posted' => $postedMembers,
                'pending_rows' => $pendingRows,
                'missing_members' => $missingMembers,
                'collection_rate' => $collectionRate,
            ],
            'cycle' => [
                'currency' => $currency,
                'pending_total' => $pendingAmountTotal,
                'late_count' => $lateCount,
                'collection_rate' => $collectionRate,
            ],
            'pipeline' => [
                'pending_contributions' => $pending,
                'posted_contributions' => $posted,
                'missing_open_period' => $missingMembers,
                'arrears_periods' => $arrearsPeriods,
                'contributions_url' => $contributionsUrl,
                'contributions_pending_url' => ContributionResource::listUrl('contributions', ['status' => ['value' => 'pending']]),
                'contributions_posted_url' => ContributionResource::listUrl('contributions', ['status' => ['value' => 'posted']]),
                'contributions_failed_url' => ContributionResource::listUrl('contributions', ['status' => ['value' => 'failed']]),
                'cycle_url' => ContributionResource::listTabUrl('collect'),
                'members_url' => MemberResource::getUrl('index'),
                'delinquency_url' => ContributionResource::listTabUrl('arrears'),
                'arrears_url' => ContributionResource::listTabUrl('arrears'),
            ],
        ];
    }

    /**
     * @return array{arrears_amount: float, recovered_amount: float, unrecovered_amount: float}
     */
    private function contributionCycleCollectionAmounts(int $month, int $year): array
    {
        $delinquency = app(LoanDelinquencyService::class);
        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        $live = $month === $openMonth && $year === $openYear;

        $collectedIds = $this->cycles->collectedContributionIdsForPeriod($month, $year);
        // Match Collected list / Amount KPI: principal cash posted for the cycle (not assessed late fees).
        $recoveredAmount = $collectedIds === []
            ? 0.0
            : (float) Contribution::query()
                ->whereIn('id', $collectedIds)
                ->sum('amount');

        $unrecoveredAmount = 0.0;
        $pendingIds = $this->cycles->pendingMemberIdsForPeriod($month, $year);

        if ($pendingIds !== []) {
            // Set-based paint path: avoid per-member standing sync + late-fee txn sums.
            $pendingRows = Contribution::query()
                ->whereIn('member_id', $pendingIds)
                ->forPeriod($month, $year)
                ->where('status', 'pending')
                ->get(['member_id', 'amount', 'amount_due', 'amount_collected', 'late_fee_amount']);

            $memberIdsWithPendingRow = [];

            foreach ($pendingRows as $row) {
                $memberIdsWithPendingRow[(int) $row->member_id] = true;
                $principalShortfall = max(
                    0.0,
                    (float) ($row->amount_due ?? $row->amount) - (float) ($row->amount_collected ?? 0),
                );
                $unrecoveredAmount += $principalShortfall + max(0.0, (float) ($row->late_fee_amount ?? 0));
            }

            $withoutRows = array_values(array_filter(
                $pendingIds,
                fn (int $memberId): bool => ! isset($memberIdsWithPendingRow[$memberId]),
            ));

            if ($withoutRows !== []) {
                $unrecoveredAmount += (float) Member::query()
                    ->whereIn('id', $withoutRows)
                    ->sum('monthly_contribution_amount');
            }
        }

        return [
            'arrears_amount' => $delinquency->contributionArrearsStatsForCycle($month, $year, $live)['amount'],
            'recovered_amount' => round($recoveredAmount, 2),
            'unrecovered_amount' => round($unrecoveredAmount, 2),
        ];
    }
}
