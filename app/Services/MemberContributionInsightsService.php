<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Concerns\EnrichesMemberPortalDashboard;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\BusinessDay;
use App\Support\Insights\DualProgressTrendBuilder;
use App\Support\Insights\InsightFormatter;
use App\Support\Tenant\CurrentMember;

final class MemberContributionInsightsService
{
    use EnrichesMemberPortalDashboard;

    public function __construct(
        protected ContributionCycleService $cycles,
        protected LoanDelinquencyService $delinquency,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?Member $member = null): array
    {
        $member = $member ?? CurrentMember::get();

        if ($member === null) {
            return [];
        }

        $member->loadMissing(['cashAccount', 'fundAccount']);
        $currency = Setting::get('general', 'currency', 'USD');
        $baseUrl = MyContributionResource::listUrl();

        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        $openPeriodLabel = $this->cycles->periodLabel($openMonth, $openYear);

        $query = Contribution::query()->where('member_id', $member->id);

        $postedCount = (int) (clone $query)->posted()->count();
        $pendingCount = (int) (clone $query)->pending()->count();
        $failedCount = (int) (clone $query)->where('status', 'failed')->count();
        $latePostedCount = (int) (clone $query)->posted()->where('is_late', true)->count();

        $totalPostedAmount = (float) (clone $query)->posted()->sum('amount');
        $lateFeesTotal = (float) (clone $query)->posted()->sum('late_fee_amount');
        $postedAmountLast12 = (float) (clone $query)
            ->posted()
            ->where('posted_at', '>=', BusinessDay::now()->subMonths(12))
            ->sum('amount');

        $openPeriodRow = (clone $query)->forPeriod($openMonth, $openYear)->first();
        $postedThisCycle = $openPeriodRow?->status === 'posted';

        $cycleStatus = $this->resolveMemberCycleStatus(
            $member,
            $postedThisCycle,
            $this->cycles,
            $openMonth,
            $openYear,
        );

        $underLoanRepayment = $member->hasActiveLoanRepaymentObligation();
        $requiredCash = $underLoanRepayment
            ? 0.0
            : $this->cycles->requiredCashForMemberPeriod($member, $openMonth, $openYear);
        $cashBalance = $member->getCashBalance();
        $cashShortfall = max(0.0, $requiredCash - $cashBalance);
        $cashReady = $cashShortfall <= 0.0;
        $cashReadyPct = $requiredCash > 0
            ? min(100, (int) round(($cashBalance / $requiredCash) * 100))
            : 100;

        $deadline = $this->cycles->deadline($openMonth, $openYear);
        $daysUntilDeadline = (int) max(0, BusinessDay::now()->diffInDays($deadline, false));

        $arrears = $this->delinquency->memberArrearsSummary($member);
        $monthly = (float) $member->monthly_contribution_amount;

        $trend = DualProgressTrendBuilder::sixMonthMemberCollectionTrend($member, $this->cycles);
        $sparkline = collect($trend)->pluck('collection_rate')->map(fn (int $rate): int => $rate)->all();

        $methodBreakdown = $this->paymentMethodBreakdown($member);
        $streak = $this->postedStreakMonths($member);
        $consistency = $this->consistencyScore($member);

        $hero = $this->buildHero(
            $member,
            $openMonth,
            $openYear,
            $openPeriodRow,
            $cycleStatus,
            $postedThisCycle,
            $cashReady,
            $cashShortfall,
            $arrears,
            $openPeriodLabel,
            $daysUntilDeadline,
        );

        return [
            'currency' => $currency,
            'monthly' => InsightFormatter::money($monthly),
            'hero' => $hero,
            'kpis' => $this->buildKpis(
                $postedCount,
                $totalPostedAmount,
                $pendingCount,
                $failedCount,
                $streak,
                $lateFeesTotal,
                $latePostedCount,
                $consistency['display'],
                $baseUrl,
            ),
            'open_cycle' => [
                'period_label' => $openPeriodLabel,
                'window' => $this->cycles->cycleWindowDescription($openMonth, $openYear),
                'status_key' => $cycleStatus['key'],
                'status_label' => $cycleStatus['label'],
                'status_tone' => $cycleStatus['tone'],
                'under_loan_repayment' => $underLoanRepayment,
                'loan_repayment_message' => $underLoanRepayment
                    ? __('Under loan repayment')
                    : null,
                'is_late' => $this->cycles->isLate($openMonth, $openYear),
                'days_until_deadline' => $daysUntilDeadline,
                'deadline_label' => $deadline->locale(app()->getLocale())->translatedFormat('j M Y'),
                'required_cash' => InsightFormatter::money($requiredCash),
                'cash_balance' => InsightFormatter::money($cashBalance),
                'cash_ready' => $cashReady,
                'cash_shortfall' => InsightFormatter::money($cashShortfall),
                'cash_shortfall_raw' => $cashShortfall,
                'cash_ready_pct' => $cashReadyPct,
                'row_status' => $openPeriodRow?->status,
                'row_amount' => $openPeriodRow !== null
                    ? InsightFormatter::money((float) $openPeriodRow->amount)
                    : null,
                'posted_this_cycle' => $postedThisCycle,
                'contributions_url' => $baseUrl,
                'deposits_url' => MyFundPostingResource::getUrl('create'),
                'cash_account_url' => $member->cashAccount
                    ? MyAccountResource::getUrl('view', ['record' => $member->cashAccount])
                    : MyAccountResource::getUrl('index'),
            ],
            'consistency' => $consistency,
            'streak' => $streak,
            'arrears' => [
                'visible' => count($arrears['unpaid_contribution_periods'] ?? []) > 0,
                'periods' => $arrears['unpaid_contribution_periods'] ?? [],
                'is_delinquent' => $arrears['is_delinquent'] ?? false,
            ],
            'trend' => $trend,
            'sparkline' => $sparkline,
            'sparkline_max' => max(1, max($sparkline)),
            'method_breakdown' => $methodBreakdown,
            'summary' => [
                'posted_count' => $postedCount,
                'pending_count' => $pendingCount,
                'failed_count' => $failedCount,
                'total_posted' => InsightFormatter::money($totalPostedAmount),
                'posted_last_12' => InsightFormatter::money($postedAmountLast12),
                'late_fees' => InsightFormatter::money($lateFeesTotal),
            ],
            'filters' => [
                'posted' => MyContributionResource::listUrl(['status' => ['value' => 'posted']]),
                'pending' => MyContributionResource::listUrl(['status' => ['value' => 'pending']]),
                'failed' => MyContributionResource::listUrl(['status' => ['value' => 'failed']]),
            ],
        ];
    }

    /**
     * @param  array{key: string, label: string, tone: string}  $cycleStatus
     * @param  array{has_arrears: bool, is_delinquent: bool, unpaid_contribution_periods: list<string>}  $arrears
     * @return array{tone: string, title: string, subtitle: string, cta_label: ?string, cta_url: ?string}
     */
    private function buildHero(
        Member $member,
        int $openMonth,
        int $openYear,
        ?Contribution $openPeriodRow,
        array $cycleStatus,
        bool $postedThisCycle,
        bool $cashReady,
        float $cashShortfall,
        array $arrears,
        string $openPeriodLabel,
        int $daysUntilDeadline,
    ): array {
        if (count($arrears['unpaid_contribution_periods'] ?? []) > 0) {
            return [
                'tone' => 'danger',
                'title' => __('Unpaid contribution periods'),
                'subtitle' => implode(' · ', $arrears['unpaid_contribution_periods']),
                'cta_label' => __('Review history'),
                'cta_url' => MyContributionResource::getUrl('index'),
            ];
        }

        if ($postedThisCycle) {
            return [
                'tone' => 'success',
                'title' => __(':period is posted', ['period' => $openPeriodLabel]),
                'subtitle' => $openPeriodRow !== null
                    ? __(':amount recorded for this cycle', ['amount' => InsightFormatter::money((float) $openPeriodRow->amount)])
                    : __('Your open cycle contribution is complete'),
                'cta_label' => null,
                'cta_url' => null,
            ];
        }

        if ($openPeriodRow?->status === 'pending') {
            return [
                'tone' => 'amber',
                'title' => __('Pending for :period', ['period' => $openPeriodLabel]),
                'subtitle' => ($openPeriodRow->is_late ?? false)
                    ? __('Late — awaiting fund posting')
                    : __('Awaiting processing by the fund office'),
                'cta_label' => __('View pending'),
                'cta_url' => MyContributionResource::listUrl(['status' => ['value' => 'pending']]),
            ];
        }

        if (
            ! $cashReady
            && $member->status === 'active'
            && (float) $member->monthly_contribution_amount > 0
            && ! $member->isExemptFromContributions()
        ) {
            return [
                'tone' => 'amber',
                'title' => __('Top up cash for :period', ['period' => $openPeriodLabel]),
                'subtitle' => __('Need :amount more in your cash account', ['amount' => InsightFormatter::money($cashShortfall)]),
                'cta_label' => __('Submit deposit'),
                'cta_url' => MyFundPostingResource::getUrl('create'),
            ];
        }

        $isLate = $this->cycles->isLate($openMonth, $openYear);

        if ($isLate && $member->status === 'active' && (float) $member->monthly_contribution_amount > 0) {
            return [
                'tone' => 'danger',
                'title' => __(':period is overdue', ['period' => $openPeriodLabel]),
                'subtitle' => __('Post or deposit before fees accrue'),
                'cta_label' => __('Submit deposit'),
                'cta_url' => MyFundPostingResource::getUrl('create'),
            ];
        }

        return [
            'tone' => match ($cycleStatus['tone']) {
                'emerald' => 'success',
                'rose', 'amber' => 'amber',
                default => 'sky',
            },
            'title' => $cycleStatus['label'],
            'subtitle' => $daysUntilDeadline > 0
                ? trans_choice(':count day left in cycle|:count days left in cycle', $daysUntilDeadline, ['count' => $daysUntilDeadline])
                : __('Cycle window closing'),
            'cta_label' => __('View cash account'),
            'cta_url' => $member->cashAccount
                ? MyAccountResource::getUrl('view', ['record' => $member->cashAccount])
                : MyAccountResource::getUrl('index'),
        ];
    }

    /**
     * @return list<array{label: string, value: string, sub: string, icon: string, accent: string, url: ?string}>
     */
    private function buildKpis(
        int $postedCount,
        float $totalPostedAmount,
        int $pendingCount,
        int $failedCount,
        int $streak,
        float $lateFeesTotal,
        int $latePostedCount,
        string $consistencyDisplay,
        string $baseUrl,
    ): array {
        return [
            [
                'label' => __('Posted'),
                'value' => (string) $postedCount,
                'sub' => InsightFormatter::money($totalPostedAmount),
                'icon' => 'heroicon-o-check-circle',
                'accent' => 'emerald',
                'url' => MyContributionResource::listUrl(['status' => ['value' => 'posted']]),
            ],
            [
                'label' => __('Pending'),
                'value' => (string) $pendingCount,
                'sub' => $pendingCount > 0 ? __('Awaiting post') : __('None'),
                'icon' => 'heroicon-o-clock',
                'accent' => $pendingCount > 0 ? 'amber' : 'gray',
                'url' => MyContributionResource::listUrl(['status' => ['value' => 'pending']]),
            ],
            [
                'label' => __('Failed'),
                'value' => (string) $failedCount,
                'sub' => __('All time'),
                'icon' => 'heroicon-o-x-circle',
                'accent' => $failedCount > 0 ? 'rose' : 'gray',
                'url' => MyContributionResource::listUrl(['status' => ['value' => 'failed']]),
            ],
            [
                'label' => __('Streak'),
                'value' => (string) $streak,
                'sub' => trans_choice(':count month|:count months', $streak, ['count' => $streak]),
                'icon' => 'heroicon-o-fire',
                'accent' => $streak >= 3 ? 'violet' : 'sky',
                'url' => null,
            ],
            [
                'label' => __('Late fees'),
                'value' => $lateFeesTotal > 0 ? InsightFormatter::compactAmount($lateFeesTotal) : '—',
                'sub' => trans_choice(':count late post|:count late posts', $latePostedCount, ['count' => $latePostedCount]),
                'icon' => 'heroicon-o-exclamation-triangle',
                'accent' => $lateFeesTotal > 0 ? 'amber' : 'teal',
                'url' => null,
            ],
            [
                'label' => __('On-time'),
                'value' => $consistencyDisplay,
                'sub' => __('Last 12 cycles'),
                'icon' => 'heroicon-o-chart-pie',
                'accent' => 'indigo',
                'url' => null,
            ],
        ];
    }

    /**
     * @return array{display: string, percent: int, posted: int, liable: int}
     */
    private function consistencyScore(Member $member): array
    {
        $postedByPeriod = $this->postedContributionsByPeriod($member, 12);
        $liable = 0;
        $onTime = 0;

        for ($i = 1; $i <= 12; $i++) {
            $month = BusinessDay::now()->subMonths($i)->startOfMonth();
            $m = (int) $month->month;
            $y = (int) $month->year;

            if (! $this->cycles->memberCanApplyContributionForPeriod($member, $m, $y)) {
                continue;
            }

            $liable++;

            $period = Contribution::periodDate($m, $y);
            $row = $postedByPeriod[$period] ?? null;

            if ($row !== null && ! $row->is_late) {
                $onTime++;
            }
        }

        $percent = $liable > 0 ? (int) round(($onTime / $liable) * 100) : 100;

        return [
            'display' => $percent.'%',
            'percent' => $percent,
            'posted' => $onTime,
            'liable' => $liable,
        ];
    }

    private function postedStreakMonths(Member $member): int
    {
        $postedByPeriod = $this->postedContributionsByPeriod($member, 24);
        $streak = 0;

        for ($i = 1; $i <= 24; $i++) {
            $month = BusinessDay::now()->subMonths($i)->startOfMonth();
            $m = (int) $month->month;
            $y = (int) $month->year;

            if (! $this->cycles->memberCanApplyContributionForPeriod($member, $m, $y)) {
                continue;
            }

            $period = Contribution::periodDate($m, $y);
            $hasPosted = isset($postedByPeriod[$period]);

            if (! $hasPosted) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    /**
     * @return array<string, Contribution>
     */
    private function postedContributionsByPeriod(Member $member, int $monthsBack): array
    {
        $oldestMonth = BusinessDay::now()->subMonths($monthsBack)->startOfMonth();
        $oldestPeriod = Contribution::periodDate((int) $oldestMonth->month, (int) $oldestMonth->year);

        return Contribution::query()
            ->where('member_id', $member->id)
            ->posted()
            ->where('period', '>=', $oldestPeriod)
            ->get(['period', 'is_late'])
            ->keyBy(fn (Contribution $contribution): string => Contribution::normalizePeriodKey($contribution->period) ?? '')
            ->all();
    }

    /**
     * @return list<array{method: string, label: string, count: int}>
     */
    private function paymentMethodBreakdown(Member $member): array
    {
        $methodCounts = Contribution::query()
            ->where('member_id', $member->id)
            ->where('status', 'posted')
            ->whereNotNull('payment_method')
            ->selectRaw('payment_method, COUNT(*) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        return collect(Contribution::paymentMethodOptions())
            ->map(fn (string $label, string $method): array => [
                'method' => $method,
                'label' => $label,
                'count' => (int) ($methodCounts[$method] ?? 0),
            ])
            ->filter(fn (array $row): bool => $row['count'] > 0)
            ->values()
            ->all();
    }
}
