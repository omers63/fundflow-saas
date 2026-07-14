<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\BusinessDay;
use App\Support\Insights\InsightKpi;

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
        [$openMonth, $openYear] = ContributionResource::resolveListCycle();
        $currency = Setting::get('general', 'currency', 'USD');
        $periodLabel = $this->cycles->periodLabel($openMonth, $openYear);

        $missingOpenPeriod = ContributionResource::pendingCountForPeriod($openMonth, $openYear);
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

        $arrearsPeriods = ContributionResource::contributionArrearsPeriodCount();

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
            'collection_amounts' => $this->contributionCycleCollectionAmounts($openMonth, $openYear),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collectedSnapshot(): array
    {
        [$openMonth, $openYear] = ContributionResource::resolveListCycle();
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
        $missingOpenPeriod = ContributionResource::pendingCountForPeriod($openMonth, $openYear);

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
                ['key' => 'amount', 'label' => __('Amount'), 'value' => $postedAmount, 'currency' => $currency, 'value_is_amount' => true, 'value_precision' => 0, 'sub' => $periodLabel, 'icon' => 'heroicon-o-currency-dollar', 'accent' => 'teal', 'active' => $postedAmount > 0],
                ['key' => 'remaining', 'label' => __('Remaining'), 'value' => (string) $missingOpenPeriod, 'sub' => __('Members'), 'icon' => 'heroicon-o-user-group', 'accent' => 'amber', 'active' => $missingOpenPeriod > 0],
                ['key' => 'contributions', 'label' => __('Contributions'), 'value' => (string) Contribution::query()->posted()->count(), 'sub' => __('All time'), 'icon' => 'heroicon-o-book-open', 'accent' => 'sky', 'active' => true],
                ['key' => 'collect', 'label' => __('To collect'), 'value' => (string) $missingOpenPeriod, 'sub' => __('Open period'), 'icon' => 'heroicon-o-arrow-down-tray', 'accent' => 'violet', 'active' => $missingOpenPeriod > 0],
                ['key' => 'arrears', 'label' => __('Arrears'), 'value' => (string) ContributionResource::contributionArrearsPeriodCount(), 'sub' => __('Past periods'), 'icon' => 'heroicon-o-banknotes', 'accent' => 'rose', 'active' => true],
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
            'collection_amounts' => $this->contributionCycleCollectionAmounts($openMonth, $openYear),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function arrearsSnapshot(): array
    {
        $delinquency = app(LoanDelinquencyService::class);
        [$month, $year] = ContributionResource::resolveListCycle();
        $live = ContributionResource::isViewingOpenCycle();
        $arrearsPeriods = ContributionResource::contributionArrearsPeriodCount();
        $arrearsMembers = $delinquency->countContributionArrearsMembers($month, $year, $live);
        $delinquentMembers = count($delinquency->delinquentMemberIds());
        $overdueInstallments = (int) LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereHas('loan', fn ($query) => $query->where('status', 'active'))
            ->count();
        $guarantorAtRisk = $delinquency->loansAtGuarantorRiskCount();

        $arrearsUrl = ContributionResource::listTabUrl('arrears');
        $currency = Setting::get('general', 'currency', 'USD');
        $periodLabel = $this->cycles->periodLabel($month, $year);

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
                'overdue' => LoanResource::listTabUrl('overdue_installments'),
                'guarantor' => LoanResource::listTabUrl('guarantor_exposure'),
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
                'overdue_url' => LoanResource::listTabUrl('overdue_installments'),
                'guarantor_url' => LoanResource::listTabUrl('guarantor_exposure'),
            ],
            'collection_amounts' => $this->contributionCycleCollectionAmounts($month, $year),
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

        $missingOpenPeriod = ContributionResource::pendingCountForPeriod($openMonth, $openYear);
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
                'label' => $this->cycles->periodLabel($openMonth, $openYear),
                'month' => $openMonth,
                'year' => $openYear,
                'is_late' => $this->cycles->isLate($openMonth, $openYear),
                'posted' => $postedOpenPeriod,
                'pending_rows' => $pendingOpenPeriod,
                'missing_members' => $missingOpenPeriod,
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
                'missing_open_period' => $missingOpenPeriod,
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

        $recoveredAmount = (float) Contribution::query()
            ->forPeriod($month, $year)
            ->posted()
            ->selectRaw('COALESCE(SUM(amount + COALESCE(late_fee_amount, 0)), 0) as total')
            ->value('total');

        $unrecoveredAmount = 0.0;
        $pendingIds = $this->cycles->pendingMemberIdsForPeriod($month, $year);

        if ($pendingIds !== []) {
            Member::query()
                ->whereIn('id', $pendingIds)
                ->with([
                    'cashAccount',
                    'contributions' => fn ($query) => $query
                        ->forPeriod($month, $year)
                        ->where('status', 'pending'),
                ])
                ->orderBy('id')
                ->each(function (Member $member) use ($month, $year, &$unrecoveredAmount): void {
                    $unrecoveredAmount += $this->cycles->requiredCollectionCashForMemberPeriod(
                        $member,
                        $month,
                        $year,
                        syncLateFees: false,
                    );
                });
        }

        return [
            'arrears_amount' => $delinquency->contributionArrearsAmountTotal(null, $month, $year, $live),
            'recovered_amount' => round($recoveredAmount, 2),
            'unrecovered_amount' => round($unrecoveredAmount, 2),
        ];
    }
}
