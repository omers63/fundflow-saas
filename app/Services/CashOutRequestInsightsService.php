<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\CashOutRequests\CashOutRequestResource;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Setting;
use App\Support\Insights\InsightFormatter;
use Carbon\Carbon;

final class CashOutRequestInsightsService
{
    private const int SLA_DAYS = 3;

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $now = Carbon::now();

        $pending = CashOutRequest::query()->where('status', 'pending')->count();
        $accepted = CashOutRequest::query()->where('status', 'accepted')->count();
        $rejected = CashOutRequest::query()->where('status', 'rejected')->count();
        $total = $pending + $accepted + $rejected;

        $newThisMonth = CashOutRequest::query()
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->count();

        $newLastMonth = CashOutRequest::query()
            ->whereMonth('created_at', $now->copy()->subMonth()->month)
            ->whereYear('created_at', $now->copy()->subMonth()->year)
            ->count();

        $acceptedThisMonth = CashOutRequest::query()
            ->where('status', 'accepted')
            ->whereMonth('reviewed_at', $now->month)
            ->whereYear('reviewed_at', $now->year)
            ->count();

        $rejectedThisMonth = CashOutRequest::query()
            ->where('status', 'rejected')
            ->whereMonth('reviewed_at', $now->month)
            ->whereYear('reviewed_at', $now->year)
            ->count();

        $decided = $accepted + $rejected;
        $acceptanceRate = $decided > 0 ? round(($accepted / $decided) * 100, 1) : null;

        $reviewedRequests = CashOutRequest::query()
            ->whereIn('status', ['accepted', 'rejected'])
            ->whereNotNull('reviewed_at')
            ->get(['created_at', 'reviewed_at']);

        $avgReviewDays = $reviewedRequests->isEmpty()
            ? 0.0
            : round((float) $reviewedRequests->avg(
                fn (CashOutRequest $request): float => (float) Carbon::parse($request->created_at)
                    ->diffInDays(Carbon::parse($request->reviewed_at))
            ), 1);

        $pendingOverSla = CashOutRequest::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $now->copy()->subDays(self::SLA_DAYS))
            ->count();

        $pendingAmountTotal = (float) CashOutRequest::query()
            ->where('status', 'pending')
            ->sum('amount');

        $acceptedAmountThisMonth = (float) CashOutRequest::query()
            ->where('status', 'accepted')
            ->whereMonth('reviewed_at', $now->month)
            ->whereYear('reviewed_at', $now->year)
            ->sum('amount');

        $pendingWithNotes = CashOutRequest::query()
            ->where('status', 'pending')
            ->whereNotNull('notes')
            ->where('notes', '!=', '')
            ->count();

        $notesRate = $pending > 0 ? (int) round(($pendingWithNotes / $pending) * 100) : 0;

        $acceptedUncleared = CashOutRequest::query()
            ->where('status', 'accepted')
            ->whereHas('bankTransaction', fn ($query) => $query->where('is_cleared', false))
            ->count();

        $acceptedWithBank = CashOutRequest::query()
            ->where('status', 'accepted')
            ->whereNotNull('bank_transaction_id')
            ->count();

        $clearedCount = max(0, $acceptedWithBank - $acceptedUncleared);
        $clearanceRate = $acceptedWithBank > 0
            ? (int) round(($clearedCount / $acceptedWithBank) * 100)
            : 0;

        $currency = Setting::get('general', 'currency', 'USD');

        $pendingUrl = CashOutRequestResource::listUrl(['status' => ['value' => 'pending']]);
        $acceptedUrl = CashOutRequestResource::listUrl(['status' => ['value' => 'accepted']]);
        $rejectedUrl = CashOutRequestResource::listUrl(['status' => ['value' => 'rejected']]);
        $indexUrl = CashOutRequestResource::listUrl();

        $oldestPending = CashOutRequest::query()
            ->with('member:id,name')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit(6)
            ->get()
            ->map(fn (CashOutRequest $request): array => [
                'id' => $request->id,
                'name' => $request->member?->name ?? __('Unknown member'),
                'amount_display' => InsightFormatter::money((float) $request->amount),
                'days_waiting' => (int) Carbon::parse($request->created_at)->diffInDays($now),
                'has_notes' => filled($request->notes),
                'queue_url' => CashOutRequestResource::indexUrlForMember((int) $request->member_id, 'pending'),
            ])
            ->all();

        return [
            'hero' => $this->buildHero($pending, $pendingAmountTotal, $pendingOverSla, $currency, $pendingUrl),
            'total' => $total,
            'pending' => $pending,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'pending_amount_total' => $pendingAmountTotal,
            'accepted_amount_this_month' => $acceptedAmountThisMonth,
            'new_this_month' => $newThisMonth,
            'new_last_month' => $newLastMonth,
            'mom_change' => $this->monthOverMonthChange($newThisMonth, $newLastMonth),
            'accepted_this_month' => $acceptedThisMonth,
            'rejected_this_month' => $rejectedThisMonth,
            'acceptance_rate' => $acceptanceRate,
            'avg_review_days' => $avgReviewDays,
            'pending_over_sla' => $pendingOverSla,
            'oldest_pending' => $oldestPending,
            'trend' => $this->sixMonthTrend(),
            'sparkline' => $this->weeklySparkline(),
            'amount_breakdown' => $this->pendingAmountBreakdown(),
            'currency' => $currency,
            'notes' => [
                'pending_total' => $pendingAmountTotal,
                'pending_with_notes' => $pendingWithNotes,
                'notes_rate' => $notesRate,
            ],
            'bank' => [
                'uncleared' => $acceptedUncleared,
                'cleared' => $clearedCount,
                'clearance_rate' => $clearanceRate,
                'workspace_url' => BankAccountsResource::listUrl('imports'),
            ],
            'pipeline' => [
                'pending_requests' => $pending,
                'accepted_requests' => $accepted,
                'uncleared_bank' => $acceptedUncleared,
                'index_url' => $indexUrl,
                'pending_url' => $pendingUrl,
                'accepted_url' => $acceptedUrl,
                'rejected_url' => $rejectedUrl,
                'bank_url' => BankAccountsResource::listUrl('imports'),
            ],
        ];
    }

    /**
     * @return array{tone: string, title: string, subtitle: string, cta_label?: string, cta_url?: string}
     */
    private function buildHero(
        int $pending,
        float $pendingAmountTotal,
        int $pendingOverSla,
        string $currency,
        string $pendingUrl,
    ): array {
        if ($pending > 0) {
            $subtitle = trans_choice(
                ':count pending · :amount :currency',
                $pending,
                [
                    'count' => $pending,
                    'amount' => number_format($pendingAmountTotal, 0),
                    'currency' => $currency,
                ],
            );

            if ($pendingOverSla > 0) {
                $subtitle .= ' · '.__(':count over SLA', ['count' => $pendingOverSla]);
            }

            return [
                'tone' => $pendingOverSla > 0 ? 'danger' : 'amber',
                'title' => __('Withdrawals need your attention'),
                'subtitle' => $subtitle,
                'cta_label' => __('Review pending'),
                'cta_url' => $pendingUrl,
            ];
        }

        return [
            'tone' => 'success',
            'title' => __('Cash-out queue clear'),
            'subtitle' => __('No pending withdrawal requests right now.'),
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
     * @return list<array{label: string, count: int, amount: float}>
     */
    private function pendingAmountBreakdown(): array
    {
        $rows = CashOutRequest::query()
            ->where('status', 'pending')
            ->selectRaw('
                SUM(CASE WHEN amount < 1000 THEN 1 ELSE 0 END) as small_count,
                SUM(CASE WHEN amount >= 1000 AND amount < 5000 THEN 1 ELSE 0 END) as medium_count,
                SUM(CASE WHEN amount >= 5000 THEN 1 ELSE 0 END) as large_count,
                SUM(CASE WHEN amount < 1000 THEN amount ELSE 0 END) as small_amount,
                SUM(CASE WHEN amount >= 1000 AND amount < 5000 THEN amount ELSE 0 END) as medium_amount,
                SUM(CASE WHEN amount >= 5000 THEN amount ELSE 0 END) as large_amount
            ')
            ->first();

        return [
            [
                'label' => __('Under 1k'),
                'count' => (int) ($rows->small_count ?? 0),
                'amount' => (float) ($rows->small_amount ?? 0),
            ],
            [
                'label' => __('1k – 5k'),
                'count' => (int) ($rows->medium_count ?? 0),
                'amount' => (float) ($rows->medium_amount ?? 0),
            ],
            [
                'label' => __('5k+'),
                'count' => (int) ($rows->large_count ?? 0),
                'amount' => (float) ($rows->large_amount ?? 0),
            ],
        ];
    }

    /**
     * @return list<array{label: string, total: int, accepted: int, rejected: int, pending: int}>
     */
    private function sixMonthTrend(): array
    {
        $now = Carbon::now();
        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();
        $monthTotals = [];

        CashOutRequest::query()
            ->whereBetween('created_at', [$oldestMonth, $now->copy()->endOfMonth()])
            ->get(['status', 'created_at'])
            ->each(function (CashOutRequest $request) use (&$monthTotals): void {
                $createdAt = $request->created_at;

                if ($createdAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $createdAt)->startOfMonth()->format('Y-m');
                $monthTotals[$key] ??= [
                    'total' => 0,
                    'accepted' => 0,
                    'rejected' => 0,
                    'pending' => 0,
                ];
                $monthTotals[$key]['total']++;

                if ($request->status === 'accepted') {
                    $monthTotals[$key]['accepted']++;

                    return;
                }

                if ($request->status === 'rejected') {
                    $monthTotals[$key]['rejected']++;

                    return;
                }

                if ($request->status === 'pending') {
                    $monthTotals[$key]['pending']++;
                }
            });

        $trend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth();
            $key = $month->format('Y-m');

            $trend[] = [
                'label' => $month->format('M'),
                'total' => (int) ($monthTotals[$key]['total'] ?? 0),
                'accepted' => (int) ($monthTotals[$key]['accepted'] ?? 0),
                'rejected' => (int) ($monthTotals[$key]['rejected'] ?? 0),
                'pending' => (int) ($monthTotals[$key]['pending'] ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * @return list<int>
     */
    private function weeklySparkline(): array
    {
        $now = Carbon::now();
        $oldestWeekStart = $now->copy()->subWeeks(7)->startOfWeek();
        $currentWeekEnd = $now->copy()->endOfWeek();
        $weekCounts = [];

        CashOutRequest::query()
            ->whereBetween('created_at', [$oldestWeekStart, $currentWeekEnd])
            ->get(['created_at'])
            ->each(function (CashOutRequest $request) use (&$weekCounts): void {
                $createdAt = $request->created_at;

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
