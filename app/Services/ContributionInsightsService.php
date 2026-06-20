<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\BusinessDay;
use App\Support\Insights\DualProgressTrendBuilder;
use App\Support\Insights\InsightKpi;
use Carbon\Carbon;

final class ContributionInsightsService
{
    public function __construct(
        protected ContributionCycleService $cycles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forContext(string $context): array
    {
        return match ($context) {
            'collect' => $this->collectSnapshot(),
            'collected' => $this->collectedSnapshot(),
            'arrears' => $this->arrearsSnapshot(),
            'contributions', 'ledger' => $this->ledgerSnapshot(),
            default => $this->ledgerSnapshot(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->ledgerSnapshot();
    }

    /**
     * @return array<string, mixed>
     */
    public function collectSnapshot(): array
    {
        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        $currency = Setting::get('general', 'currency', 'USD');
        $periodLabel = $this->cycles->periodLabel($openMonth, $openYear);

        $missingOpenPeriod = $this->cycles->pendingMembersQueryForPeriod($openMonth, $openYear)->count();
        $postedOpenPeriod = Contribution::query()
            ->forPeriod($openMonth, $openYear)
            ->posted()
            ->count();
        $pendingOpenPeriod = Contribution::query()
            ->forPeriod($openMonth, $openYear)
            ->pending()
            ->count();
        $lateOpenPeriod = Contribution::query()
            ->forPeriod($openMonth, $openYear)
            ->pending()
            ->where('is_late', true)
            ->count();

        $openDenominator = $postedOpenPeriod + $missingOpenPeriod;
        $collectionRate = $openDenominator > 0
            ? (int) round(($postedOpenPeriod / $openDenominator) * 100)
            : 0;

        $delinquency = app(LoanDelinquencyService::class);
        $arrearsPeriods = $delinquency->countContributionArrearsPeriods();

        $collectUrl = ContributionResource::listTabUrl('collect');

        return [
            'currency' => $currency,
            'open_period' => [
                'label' => $periodLabel,
                'collection_rate' => $collectionRate,
                'missing_members' => $missingOpenPeriod,
            ],
            'hero' => [
                'tone' => $missingOpenPeriod > 0 ? 'amber' : 'success',
                'title' => $missingOpenPeriod > 0
                    ? __('Open period collection in progress')
                    : __('Open period fully collected'),
                'subtitle' => $missingOpenPeriod > 0
                    ? trans_choice(
                        ':count member still to collect for :period|:count members still to collect for :period',
                        $missingOpenPeriod,
                        ['count' => $missingOpenPeriod, 'period' => $periodLabel],
                    )
                    : __('All members have posted for :period.', ['period' => $periodLabel]),
                'cta_label' => $missingOpenPeriod > 0 ? __('To collect') : null,
                'cta_url' => $missingOpenPeriod > 0 ? $collectUrl : null,
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'missing', 'label' => __('To collect'), 'value' => (string) $missingOpenPeriod, 'sub' => __('Members'), 'icon' => 'heroicon-o-user-group', 'accent' => 'amber', 'active' => $missingOpenPeriod > 0],
                ['key' => 'posted', 'label' => __('Posted'), 'value' => (string) $postedOpenPeriod, 'sub' => $periodLabel, 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true],
                ['key' => 'pending', 'label' => __('Pending rows'), 'value' => (string) $pendingOpenPeriod, 'sub' => __('Ledger'), 'icon' => 'heroicon-o-clock', 'accent' => 'sky', 'active' => $pendingOpenPeriod > 0],
                ['key' => 'rate', 'label' => __('Collection'), 'value' => $collectionRate.'%', 'sub' => __('Open period'), 'icon' => 'heroicon-o-chart-pie', 'accent' => 'violet', 'active' => true],
                ['key' => 'late', 'label' => __('Late'), 'value' => (string) $lateOpenPeriod, 'sub' => __('Open period'), 'icon' => 'heroicon-o-exclamation-triangle', 'accent' => 'rose', 'active' => $lateOpenPeriod > 0],
                ['key' => 'arrears', 'label' => __('Arrears'), 'value' => (string) $arrearsPeriods, 'sub' => __('Past periods'), 'icon' => 'heroicon-o-banknotes', 'accent' => 'rose', 'active' => $arrearsPeriods > 0],
            ], [
                'missing' => $collectUrl,
                'posted' => ContributionResource::listTabUrl('collected'),
                'pending' => ContributionResource::listUrl('contributions', ['status' => ['value' => 'pending']]),
                'rate' => $collectUrl,
                'late' => ContributionResource::listUrl('contributions', ['status' => ['value' => 'pending']]),
                'arrears' => ContributionResource::listTabUrl('arrears'),
            ]),
            'pipeline' => [
                'missing_open_period' => $missingOpenPeriod,
                'posted_open_period' => $postedOpenPeriod,
                'pending_open_period' => $pendingOpenPeriod,
                'arrears_periods' => $arrearsPeriods,
                'collect_url' => $collectUrl,
                'collected_url' => ContributionResource::listTabUrl('collected'),
                'arrears_url' => ContributionResource::listTabUrl('arrears'),
                'ledger_pending_url' => ContributionResource::listUrl('contributions', ['status' => ['value' => 'pending']]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collectedSnapshot(): array
    {
        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        $currency = Setting::get('general', 'currency', 'USD');
        $periodLabel = $this->cycles->periodLabel($openMonth, $openYear);

        $postedOpenPeriod = Contribution::query()
            ->forPeriod($openMonth, $openYear)
            ->posted()
            ->count();
        $postedAmount = (float) Contribution::query()
            ->forPeriod($openMonth, $openYear)
            ->posted()
            ->sum('amount');
        $missingOpenPeriod = $this->cycles->pendingMembersQueryForPeriod($openMonth, $openYear)->count();

        $collectedUrl = ContributionResource::listTabUrl('collected');

        return [
            'currency' => $currency,
            'open_period' => ['label' => $periodLabel],
            'hero' => [
                'tone' => 'success',
                'title' => __('Collected for :period', ['period' => $periodLabel]),
                'subtitle' => trans_choice(
                    ':count posted contribution row|:count posted contribution rows',
                    $postedOpenPeriod,
                    ['count' => $postedOpenPeriod],
                ).($missingOpenPeriod > 0
                    ? ' · '.trans_choice(':count member still on To collect|:count members still on To collect', $missingOpenPeriod, ['count' => $missingOpenPeriod])
                    : ''),
                'cta_label' => $missingOpenPeriod > 0 ? __('To collect') : null,
                'cta_url' => $missingOpenPeriod > 0 ? ContributionResource::listTabUrl('collect') : null,
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'posted', 'label' => __('Posted'), 'value' => (string) $postedOpenPeriod, 'sub' => $periodLabel, 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true],
                ['key' => 'amount', 'label' => __('Amount'), 'value' => $postedAmount, 'currency' => $currency, 'value_precision' => 0, 'sub' => $periodLabel, 'icon' => 'heroicon-o-currency-dollar', 'accent' => 'teal', 'active' => $postedAmount > 0],
                ['key' => 'remaining', 'label' => __('Remaining'), 'value' => (string) $missingOpenPeriod, 'sub' => __('Members'), 'icon' => 'heroicon-o-user-group', 'accent' => 'amber', 'active' => $missingOpenPeriod > 0],
                ['key' => 'contributions', 'label' => __('Contributions'), 'value' => (string) Contribution::query()->posted()->count(), 'sub' => __('All time'), 'icon' => 'heroicon-o-book-open', 'accent' => 'sky', 'active' => true],
                ['key' => 'collect', 'label' => __('To collect'), 'value' => (string) $missingOpenPeriod, 'sub' => __('Open period'), 'icon' => 'heroicon-o-arrow-down-tray', 'accent' => 'violet', 'active' => $missingOpenPeriod > 0],
                ['key' => 'arrears', 'label' => __('Arrears'), 'value' => (string) app(LoanDelinquencyService::class)->countContributionArrearsPeriods(), 'sub' => __('Past periods'), 'icon' => 'heroicon-o-banknotes', 'accent' => 'rose', 'active' => true],
            ], [
                'posted' => $collectedUrl,
                'amount' => $collectedUrl,
                'remaining' => ContributionResource::listTabUrl('collect'),
                'contributions' => ContributionResource::listUrl('contributions'),
                'collect' => ContributionResource::listTabUrl('collect'),
                'arrears' => ContributionResource::listTabUrl('arrears'),
            ]),
            'pipeline' => [
                'posted_open_period' => $postedOpenPeriod,
                'missing_open_period' => $missingOpenPeriod,
                'collected_url' => $collectedUrl,
                'collect_url' => ContributionResource::listTabUrl('collect'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function arrearsSnapshot(): array
    {
        $delinquency = app(LoanDelinquencyService::class);
        $counts = $delinquency->digestCounts();
        $arrearsPeriods = $delinquency->countContributionArrearsPeriods();
        $arrearsMembers = $counts['contribution_arrears_members'];
        $delinquentMembers = $counts['delinquent_members'];

        $arrearsUrl = ContributionResource::listTabUrl('arrears');

        return [
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
                ['key' => 'overdue', 'label' => __('Overdue EMIs'), 'value' => (string) $counts['overdue_installments'], 'sub' => __('Loans'), 'icon' => 'heroicon-o-calendar-days', 'accent' => 'rose', 'active' => $counts['overdue_installments'] > 0],
                ['key' => 'guarantor', 'label' => __('Guarantor'), 'value' => (string) $counts['guarantor_at_risk'], 'sub' => __('Exposure'), 'icon' => 'heroicon-o-shield-exclamation', 'accent' => 'amber', 'active' => $counts['guarantor_at_risk'] > 0],
            ], [
                'arrears' => $arrearsUrl,
                'members' => $arrearsUrl,
                'delinquent' => MemberResource::listTabUrl('delinquent'),
                'collect' => ContributionResource::listTabUrl('collect'),
                'overdue' => LoanResource::listTabUrl('overdue_installments'),
                'guarantor' => LoanResource::listTabUrl('guarantor_exposure'),
            ]),
            'pipeline' => [
                'arrears_periods' => $arrearsPeriods,
                'arrears_members' => $arrearsMembers,
                'delinquent_members' => $delinquentMembers,
                'overdue_installments' => $counts['overdue_installments'],
                'guarantor_at_risk' => $counts['guarantor_at_risk'],
                'arrears_url' => $arrearsUrl,
                'collect_url' => ContributionResource::listTabUrl('collect'),
                'delinquent_url' => MemberResource::listTabUrl('delinquent'),
                'overdue_url' => LoanResource::listTabUrl('overdue_installments'),
                'guarantor_url' => LoanResource::listTabUrl('guarantor_exposure'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ledgerSnapshot(): array
    {
        $now = BusinessDay::now();
        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();

        $pending = Contribution::query()->where('status', 'pending')->count();
        $posted = Contribution::query()->where('status', 'posted')->count();
        $failed = Contribution::query()->where('status', 'failed')->count();
        $total = $pending + $posted + $failed;

        $pendingAmountTotal = (float) Contribution::query()
            ->where('status', 'pending')
            ->sum('amount');

        $postedAmountThisMonth = (float) Contribution::query()
            ->where('status', 'posted')
            ->whereMonth('posted_at', $now->month)
            ->whereYear('posted_at', $now->year)
            ->sum('amount');

        $postedThisMonth = Contribution::query()
            ->where('status', 'posted')
            ->whereMonth('posted_at', $now->month)
            ->whereYear('posted_at', $now->year)
            ->count();

        $lateCount = Contribution::query()
            ->where('status', 'pending')
            ->where('is_late', true)
            ->count();

        $missingOpenPeriod = $this->cycles->pendingMembersQueryForPeriod($openMonth, $openYear)->count();
        $postedOpenPeriod = Contribution::query()
            ->forPeriod($openMonth, $openYear)
            ->posted()
            ->count();
        $pendingOpenPeriod = Contribution::query()
            ->forPeriod($openMonth, $openYear)
            ->pending()
            ->count();

        $openDenominator = $postedOpenPeriod + $missingOpenPeriod;
        $collectionRate = $openDenominator > 0
            ? (int) round(($postedOpenPeriod / $openDenominator) * 100)
            : 0;

        $newThisMonth = Contribution::query()
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->count();

        $newLastMonth = Contribution::query()
            ->whereMonth('created_at', $now->copy()->subMonth()->month)
            ->whereYear('created_at', $now->copy()->subMonth()->year)
            ->count();

        $oldestPending = Contribution::query()
            ->with('member:id,name')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit(6)
            ->get()
            ->map(fn (Contribution $contribution): array => [
                'id' => $contribution->id,
                'name' => $contribution->member?->name ?? __('Unknown member'),
                'period_label' => $contribution->period !== null
                    ? Carbon::parse((string) $contribution->period)
                        ->locale(app()->getLocale())
                        ->translatedFormat('M Y')
                    : '—',
                'amount' => (float) $contribution->amount,
                'is_late' => (bool) $contribution->is_late,
                'days_waiting' => (int) Carbon::parse($contribution->created_at)->diffInDays($now),
                'queue_url' => ContributionResource::listUrl('contributions', array_merge(
                    ContributionResource::memberFilter((int) $contribution->member_id),
                    ['status' => ['value' => 'pending']],
                )),
            ])
            ->all();

        $methodCounts = Contribution::query()
            ->where('status', 'posted')
            ->whereNotNull('payment_method')
            ->selectRaw('payment_method, COUNT(*) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $methodBreakdown = collect(Contribution::paymentMethodOptions())
            ->map(fn (string $label, string $method): array => [
                'method' => $method,
                'label' => $label,
                'count' => (int) ($methodCounts[$method] ?? 0),
            ])
            ->values()
            ->all();

        $currency = Setting::get('general', 'currency', 'USD');
        $contributionsUrl = ContributionResource::listUrl('contributions');

        return [
            'total' => $total,
            'pending' => $pending,
            'posted' => $posted,
            'failed' => $failed,
            'pending_amount_total' => $pendingAmountTotal,
            'posted_amount_this_month' => $postedAmountThisMonth,
            'posted_this_month' => $postedThisMonth,
            'late_count' => $lateCount,
            'new_this_month' => $newThisMonth,
            'new_last_month' => $newLastMonth,
            'mom_change' => $this->monthOverMonthChange($newThisMonth, $newLastMonth),
            'open_period' => [
                'label' => $this->cycles->periodLabel($openMonth, $openYear),
                'month' => $openMonth,
                'year' => $openYear,
                'is_late' => $this->cycles->isLate($openMonth, $openYear),
                'posted' => $postedOpenPeriod,
                'pending_rows' => $pendingOpenPeriod,
                'missing_members' => $missingOpenPeriod,
                'collection_rate' => $collectionRate,
            ],
            'oldest_pending' => $oldestPending,
            'trend' => DualProgressTrendBuilder::sixMonthFundCollectionTrend($this->cycles),
            'sparkline' => $this->weeklySparkline(),
            'method_breakdown' => $methodBreakdown,
            'cycle' => [
                'currency' => $currency,
                'pending_total' => $pendingAmountTotal,
                'late_count' => $lateCount,
                'collection_rate' => $collectionRate,
            ],
            'pipeline' => [
                'pending_contributions' => $pending,
                'posted_contributions' => $posted,
                'missing_open_period' => $missingOpenPeriod,
                'contributions_url' => $contributionsUrl,
                'contributions_pending_url' => ContributionResource::listUrl('contributions', ['status' => ['value' => 'pending']]),
                'contributions_posted_url' => ContributionResource::listUrl('contributions', ['status' => ['value' => 'posted']]),
                'contributions_failed_url' => ContributionResource::listUrl('contributions', ['status' => ['value' => 'failed']]),
                'cycle_url' => ContributionResource::listTabUrl('collect'),
                'members_url' => MemberResource::getUrl('index'),
                'delinquency_url' => ContributionResource::listTabUrl('arrears'),
            ],
        ];
    }

    private function monthOverMonthChange(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return $current > 0 ? 100 : null;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    /**
     * @return list<int>
     */
    private function weeklySparkline(): array
    {
        $now = BusinessDay::now();
        $oldestWeekStart = $now->copy()->subWeeks(7)->startOfWeek();
        $currentWeekEnd = $now->copy()->endOfWeek();
        $weekCounts = [];

        Contribution::query()
            ->whereBetween('created_at', [$oldestWeekStart, $currentWeekEnd])
            ->get(['created_at'])
            ->each(function (Contribution $contribution) use (&$weekCounts): void {
                $createdAt = $contribution->created_at;

                if ($createdAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $createdAt)->startOfWeek()->toDateString();
                $weekCounts[$key] = ($weekCounts[$key] ?? 0) + 1;
            });

        $points = [];

        for ($i = 7; $i >= 0; $i--) {
            $start = $now->copy()->subWeeks($i)->startOfWeek()->toDateString();
            $points[] = $weekCounts[$start] ?? 0;
        }

        return $points;
    }
}
