<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Setting;
use App\Support\BusinessDay;
use App\Support\Insights\DualProgressTrendBuilder;
use App\Support\Insights\InsightFormatter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;

final class MonthlyStatementInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $now = BusinessDay::now();
        $latestPeriod = $now->copy()->subMonthNoOverflow()->format('Y-m');
        $currency = Setting::get('general', 'currency', 'USD');
        $statementsUrl = MonthlyStatementResource::getUrl('index');

        $total = MonthlyStatement::query()->count();
        $pendingNotify = MonthlyStatement::query()->whereNull('notified_at')->count();
        $notified = MonthlyStatement::query()->whereNotNull('notified_at')->count();
        $notifyRate = $total > 0 ? (int) round(($notified / $total) * 100) : 0;

        $generatedThisMonth = MonthlyStatement::query()
            ->whereMonth('generated_at', $now->month)
            ->whereYear('generated_at', $now->year)
            ->count();

        $generatedLastMonth = MonthlyStatement::query()
            ->whereMonth('generated_at', $now->copy()->subMonth()->month)
            ->whereYear('generated_at', $now->copy()->subMonth()->year)
            ->count();

        $activeMembers = Member::query()->active()->count();
        $latestPeriodCount = MonthlyStatement::query()->where('period', $latestPeriod)->count();
        $missingLatest = max(0, $activeMembers - $latestPeriodCount);
        $coverageRate = $activeMembers > 0
            ? (int) round(($latestPeriodCount / $activeMembers) * 100)
            : 0;

        $latestPeriodStats = MonthlyStatement::query()
            ->where('period', $latestPeriod)
            ->selectRaw('
                COALESCE(SUM(total_contributions), 0) as contrib_sum,
                COALESCE(SUM(total_repayments), 0) as repay_sum,
                COALESCE(AVG(closing_balance), 0) as avg_closing,
                SUM(CASE WHEN notified_at IS NULL THEN 1 ELSE 0 END) as unsent_count,
                SUM(CASE WHEN notified_at IS NOT NULL THEN 1 ELSE 0 END) as sent_count
            ')
            ->first();

        $latestPeriodUnsent = (int) ($latestPeriodStats->unsent_count ?? 0);
        $latestPeriodSent = (int) ($latestPeriodStats->sent_count ?? 0);
        $latestPeriodNotifyRate = $latestPeriodCount > 0
            ? (int) round(($latestPeriodSent / $latestPeriodCount) * 100)
            : 0;

        $unsentUrl = $this->statementsFilterUrl([
            'notified' => ['value' => '0'],
        ]);
        $sentUrl = $this->statementsFilterUrl([
            'notified' => ['value' => '1'],
        ]);
        $latestPeriodUrl = $this->statementsFilterUrl([
            'period' => ['period' => $latestPeriod],
        ]);
        $latestPeriodUnsentUrl = $this->statementsFilterUrl([
            'period' => ['period' => $latestPeriod],
            'notified' => ['value' => '0'],
        ]);

        $unnotifiedQueue = MonthlyStatement::query()
            ->with('member:id,name,member_number')
            ->whereNull('notified_at')
            ->orderBy('generated_at')
            ->limit(6)
            ->get()
            ->map(fn (MonthlyStatement $statement): array => $this->queueRow($statement, $now, $unsentUrl))
            ->all();

        return [
            'total' => $total,
            'pending_notify' => $pendingNotify,
            'notified' => $notified,
            'notify_rate' => $notifyRate,
            'generated_this_month' => $generatedThisMonth,
            'generated_last_month' => $generatedLastMonth,
            'mom_change' => $this->monthOverMonthChange($generatedThisMonth, $generatedLastMonth),
            'needs_attention' => $pendingNotify + $missingLatest,
            'latest_period' => [
                'key' => $latestPeriod,
                'label' => $this->periodLabel($latestPeriod),
                'count' => $latestPeriodCount,
                'missing' => $missingLatest,
                'coverage_rate' => $coverageRate,
                'notify_rate' => $latestPeriodNotifyRate,
                'unsent' => $latestPeriodUnsent,
                'contrib_sum' => (float) ($latestPeriodStats->contrib_sum ?? 0),
                'repay_sum' => (float) ($latestPeriodStats->repay_sum ?? 0),
                'avg_closing' => (float) ($latestPeriodStats->avg_closing ?? 0),
                'url' => $latestPeriodUrl,
                'unsent_url' => $latestPeriodUnsentUrl,
            ],
            'unnotified_queue' => $unnotifiedQueue,
            'trend' => $this->sixMonthTrend(),
            'sparkline' => $this->weeklySparkline(),
            'period_breakdown' => $this->periodBreakdown(),
            'delivery' => [
                'currency' => $currency,
                'notify_rate' => $notifyRate,
                'pending_notify' => $pendingNotify,
                'coverage_rate' => $coverageRate,
            ],
            'pipeline' => [
                'total_statements' => $total,
                'pending_notify' => $pendingNotify,
                'missing_latest' => $missingLatest,
                'statements_url' => $statementsUrl,
                'unsent_url' => $unsentUrl,
                'sent_url' => $sentUrl,
                'latest_period_url' => $latestPeriodUrl,
                'members_url' => MemberResource::getUrl('index'),
                'latest_period' => $latestPeriod,
            ],
            'filter_urls' => [
                'all' => $statementsUrl,
                'unsent' => $unsentUrl,
                'sent' => $sentUrl,
                'latest_period' => $latestPeriodUrl,
                'latest_period_unsent' => $latestPeriodUnsentUrl,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function memberSnapshot(?int $memberId): array
    {
        if ($memberId === null) {
            return [];
        }

        $now = BusinessDay::now();
        $currency = Setting::get('general', 'currency', 'USD');

        $total = MonthlyStatement::query()->where('member_id', $memberId)->count();
        $latest = MonthlyStatement::query()
            ->where('member_id', $memberId)
            ->orderByDesc('period')
            ->first();

        $generatedThisYear = MonthlyStatement::query()
            ->where('member_id', $memberId)
            ->whereYear('generated_at', $now->year)
            ->count();

        $trend = [];
        $statements = MonthlyStatement::query()
            ->where('member_id', $memberId)
            ->orderByDesc('period')
            ->limit(6)
            ->get()
            ->sortBy('period')
            ->values();

        foreach ($statements as $statement) {
            $trend[] = [
                'label' => $this->periodLabel($statement->period),
                'total' => 1,
                'contributions' => (float) $statement->total_contributions,
                'repayments' => (float) $statement->total_repayments,
                'closing' => (float) $statement->closing_balance,
            ];
        }

        $sparkline = $statements->pluck('total_contributions')->map(fn ($v) => (int) round((float) $v))->all();
        while (count($sparkline) < 8) {
            array_unshift($sparkline, 0);
        }
        $sparkline = array_slice($sparkline, -8);

        $details = is_array($latest?->details) ? $latest->details : [];

        return [
            'total' => $total,
            'generated_this_year' => $generatedThisYear,
            'latest' => $latest ? [
                'period' => $latest->period,
                'period_label' => $latest->period_formatted,
                'closing' => (float) $latest->closing_balance,
                'closing_display' => InsightFormatter::money((float) $latest->closing_balance),
                'contributions' => (float) $latest->total_contributions,
                'repayments' => (float) $latest->total_repayments,
                'cash_closing' => (float) ($details['cash_closing'] ?? 0),
                'fund_closing' => (float) ($details['fund_closing'] ?? 0),
                'notified' => $latest->notified_at !== null,
                'pdf_url' => Route::has('tenant.member.statement.pdf')
                    ? route('tenant.member.statement.pdf', $latest)
                    : null,
            ] : null,
            'trend' => $trend,
            'sparkline' => $sparkline,
            'currency' => $currency,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    private function statementsFilterUrl(array $filters = []): string
    {
        $url = MonthlyStatementResource::getUrl('index');

        if ($filters === []) {
            return $url;
        }

        return $url.'?'.http_build_query(['tableFilters' => $filters]);
    }

    /**
     * @return array{id: int, name: string, period_label: string, closing_display: string, days_waiting: int, queue_url: string, pdf_url: string|null}
     */
    private function queueRow(MonthlyStatement $statement, Carbon $now, string $unsentUrl): array
    {
        $search = $statement->member?->name;

        return [
            'id' => $statement->id,
            'name' => $statement->member?->name ?? __('Unknown member'),
            'period_label' => $this->periodLabel($statement->period),
            'closing_display' => InsightFormatter::money((float) $statement->closing_balance),
            'days_waiting' => (int) Carbon::parse($statement->generated_at)->diffInDays($now),
            'queue_url' => $unsentUrl.(filled($search) ? '&'.http_build_query(['tableSearch' => $search]) : ''),
            'pdf_url' => Route::has('tenant.admin.statement.pdf')
                ? route('tenant.admin.statement.pdf', $statement)
                : null,
        ];
    }

    private function periodLabel(string $period): string
    {
        $parts = explode('-', $period);
        if (count($parts) !== 2) {
            return $period;
        }

        return Carbon::create((int) $parts[0], (int) $parts[1], 1)
            ->locale(app()->getLocale())
            ->translatedFormat('M Y');
    }

    private function monthOverMonthChange(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return $current > 0 ? 100 : null;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    /**
     * @return list<array{label: string, total: int, notified: int, pending: int}>
     */
    private function sixMonthTrend(): array
    {
        $now = BusinessDay::now();
        $oldestPeriod = $now->copy()->subMonths(5)->format('Y-m');
        $periodTotals = [];

        MonthlyStatement::query()
            ->where('period', '>=', $oldestPeriod)
            ->get(['period', 'notified_at'])
            ->each(function (MonthlyStatement $statement) use (&$periodTotals): void {
                $period = (string) $statement->period;
                $periodTotals[$period] ??= [
                    'total' => 0,
                    'notified' => 0,
                    'pending' => 0,
                ];
                $periodTotals[$period]['total']++;

                if ($statement->notified_at !== null) {
                    $periodTotals[$period]['notified']++;

                    return;
                }

                $periodTotals[$period]['pending']++;
            });

        $trend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth();
            $period = $month->format('Y-m');

            $trend[] = [
                'label' => $month->format('M'),
                'total' => (int) ($periodTotals[$period]['total'] ?? 0),
                'notified' => (int) ($periodTotals[$period]['notified'] ?? 0),
                'pending' => (int) ($periodTotals[$period]['pending'] ?? 0),
            ];
        }

        return array_map(
            fn (array $month): array => DualProgressTrendBuilder::buildWorkflowMonthRow(
                $month['label'],
                $month['total'],
                $month['notified'],
                $month['total'] - $month['pending'],
            ),
            $trend,
        );
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

        MonthlyStatement::query()
            ->whereBetween('generated_at', [$oldestWeekStart, $currentWeekEnd])
            ->get(['generated_at'])
            ->each(function (MonthlyStatement $statement) use (&$weekCounts): void {
                $generatedAt = $statement->generated_at;

                if ($generatedAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $generatedAt)->startOfWeek()->toDateString();
                $weekCounts[$key] = ($weekCounts[$key] ?? 0) + 1;
            });

        $points = [];

        for ($i = 7; $i >= 0; $i--) {
            $start = $now->copy()->subWeeks($i)->startOfWeek()->toDateString();
            $points[] = $weekCounts[$start] ?? 0;
        }

        return $points;
    }

    /**
     * @return list<array{label: string, period: string, count: int}>
     */
    private function periodBreakdown(): array
    {
        return MonthlyStatement::query()
            ->selectRaw('period, COUNT(*) as total')
            ->groupBy('period')
            ->orderByDesc('period')
            ->limit(6)
            ->get()
            ->map(fn ($row): array => [
                'period' => (string) $row->period,
                'label' => $this->periodLabel((string) $row->period),
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();
    }
}
