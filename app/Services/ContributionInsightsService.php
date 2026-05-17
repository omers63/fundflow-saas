<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Pages\ContributionCyclePage;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Setting;
use App\Support\Insights\InsightFormatter;
use Carbon\Carbon;

final class ContributionInsightsService
{
    public function __construct(
        protected ContributionCycleService $cycles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $now = Carbon::now();
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
                'period_label' => $contribution->period?->translatedFormat('M Y') ?? '—',
                'amount_display' => InsightFormatter::money((float) $contribution->amount),
                'is_late' => (bool) $contribution->is_late,
                'days_waiting' => (int) Carbon::parse($contribution->created_at)->diffInDays($now),
                'queue_url' => ContributionResource::getUrl('index')
                    .'?tableFilters[member_id][value]='.$contribution->member_id
                    .'&tableFilters[status][value]=pending',
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
        $contributionsUrl = ContributionResource::getUrl('index');

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
            'trend' => $this->sixMonthTrend(),
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
                'cycle_url' => ContributionCyclePage::getUrl(),
                'members_url' => MemberResource::getUrl('index'),
                'delinquency_url' => LoanResource::getUrl('delinquency'),
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
     * @return list<array{label: string, total: int, posted: int, pending: int, failed: int}>
     */
    private function sixMonthTrend(): array
    {
        $trend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $period = Contribution::periodDate((int) $month->month, (int) $month->year);

            $row = Contribution::query()
                ->where('period', $period)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) as posted,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                ")
                ->first();

            $trend[] = [
                'label' => $month->format('M'),
                'total' => (int) ($row->total ?? 0),
                'posted' => (int) ($row->posted ?? 0),
                'pending' => (int) ($row->pending ?? 0),
                'failed' => (int) ($row->failed ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * @return list<int>
     */
    private function weeklySparkline(): array
    {
        $points = [];

        for ($i = 7; $i >= 0; $i--) {
            $start = Carbon::now()->subWeeks($i)->startOfWeek();
            $end = $start->copy()->endOfWeek();

            $points[] = Contribution::query()
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        return $points;
    }
}
