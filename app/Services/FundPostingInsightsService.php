<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Setting;
use App\Support\Insights\DualProgressTrendBuilder;
use App\Support\Insights\InsightFormatter;
use Carbon\Carbon;

final class FundPostingInsightsService
{
    private const int SLA_DAYS = 3;

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $now = Carbon::now();

        $pending = FundPosting::query()->where('status', 'pending')->count();
        $accepted = FundPosting::query()->where('status', 'accepted')->count();
        $rejected = FundPosting::query()->where('status', 'rejected')->count();
        $total = $pending + $accepted + $rejected;

        $newThisMonth = FundPosting::query()
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->count();

        $newLastMonth = FundPosting::query()
            ->whereMonth('created_at', $now->copy()->subMonth()->month)
            ->whereYear('created_at', $now->copy()->subMonth()->year)
            ->count();

        $acceptedThisMonth = FundPosting::query()
            ->where('status', 'accepted')
            ->whereMonth('reviewed_at', $now->month)
            ->whereYear('reviewed_at', $now->year)
            ->count();

        $rejectedThisMonth = FundPosting::query()
            ->where('status', 'rejected')
            ->whereMonth('reviewed_at', $now->month)
            ->whereYear('reviewed_at', $now->year)
            ->count();

        $decided = $accepted + $rejected;
        $acceptanceRate = $decided > 0 ? round(($accepted / $decided) * 100, 1) : null;

        $reviewedPostings = FundPosting::query()
            ->whereIn('status', ['accepted', 'rejected'])
            ->whereNotNull('reviewed_at')
            ->get(['created_at', 'reviewed_at']);

        $avgReviewDays = $reviewedPostings->isEmpty()
            ? 0.0
            : round((float) $reviewedPostings->avg(
                fn (FundPosting $posting): float => (float) Carbon::parse($posting->created_at)
                    ->diffInDays(Carbon::parse($posting->reviewed_at))
            ), 1);

        $pendingOverSla = FundPosting::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $now->copy()->subDays(self::SLA_DAYS))
            ->count();

        $depositsUrl = FundPostingResource::listUrl();

        $oldestPending = FundPosting::query()
            ->with('member:id,name')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit(6)
            ->get()
            ->map(fn (FundPosting $posting): array => [
                'id' => $posting->id,
                'name' => $posting->member?->name ?? __('Unknown member'),
                'amount' => (float) $posting->amount,
                'amount_display' => InsightFormatter::money((float) $posting->amount),
                'days_waiting' => (int) Carbon::parse($posting->created_at)->diffInDays($now),
                'has_receipt' => filled($posting->attachment),
                'queue_url' => FundPostingResource::indexUrlForMember((int) $posting->member_id, 'pending'),
            ])
            ->all();

        $pendingAmountTotal = (float) FundPosting::query()
            ->where('status', 'pending')
            ->sum('amount');

        $acceptedAmountThisMonth = (float) FundPosting::query()
            ->where('status', 'accepted')
            ->whereMonth('reviewed_at', $now->month)
            ->whereYear('reviewed_at', $now->year)
            ->sum('amount');

        $pendingWithReceipt = FundPosting::query()
            ->where('status', 'pending')
            ->whereNotNull('attachment')
            ->where('attachment', '!=', '')
            ->count();

        $receiptRate = $pending > 0 ? (int) round(($pendingWithReceipt / $pending) * 100) : 0;

        $acceptedUncleared = FundPosting::query()
            ->where('status', 'accepted')
            ->whereHas('bankTransaction', fn ($query) => $query->where('is_cleared', false))
            ->count();

        $acceptedWithBank = FundPosting::query()
            ->where('status', 'accepted')
            ->whereNotNull('bank_transaction_id')
            ->count();

        $clearedCount = max(0, $acceptedWithBank - $acceptedUncleared);
        $clearanceRate = $acceptedWithBank > 0
            ? (int) round(($clearedCount / $acceptedWithBank) * 100)
            : 0;

        $currency = Setting::get('general', 'currency', 'USD');

        return [
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
            'docs' => [
                'currency' => $currency,
                'pending_total' => $pendingAmountTotal,
                'pending_with_receipt' => $pendingWithReceipt,
                'receipt_rate' => $receiptRate,
            ],
            'bank' => [
                'uncleared' => $acceptedUncleared,
                'cleared' => $clearedCount,
                'clearance_rate' => $clearanceRate,
                'workspace_url' => BankAccountsResource::getUrl('index', ['tab' => 'imports']),
            ],
            'pipeline' => [
                'pending_deposits' => $pending,
                'accepted_deposits' => $accepted,
                'uncleared_bank' => $acceptedUncleared,
                'deposits_url' => $depositsUrl,
                'deposits_pending_url' => FundPostingResource::listUrl(['status' => ['value' => 'pending']]),
                'deposits_accepted_url' => FundPostingResource::listUrl(['status' => ['value' => 'accepted']]),
                'deposits_rejected_url' => FundPostingResource::listUrl(['status' => ['value' => 'rejected']]),
                'bank_url' => BankAccountsResource::listUrl('imports'),
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
     * @return list<array{label: string, count: int, amount: float}>
     */
    private function pendingAmountBreakdown(): array
    {
        $rows = FundPosting::query()
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

        FundPosting::query()
            ->whereBetween('created_at', [$oldestMonth, $now->copy()->endOfMonth()])
            ->get(['status', 'created_at'])
            ->each(function (FundPosting $posting) use (&$monthTotals): void {
                $createdAt = $posting->created_at;

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

                if ($posting->status === 'accepted') {
                    $monthTotals[$key]['accepted']++;

                    return;
                }

                if ($posting->status === 'rejected') {
                    $monthTotals[$key]['rejected']++;

                    return;
                }

                if ($posting->status === 'pending') {
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

        return DualProgressTrendBuilder::mapWorkflowTrend($trend);
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

        FundPosting::query()
            ->whereBetween('created_at', [$oldestWeekStart, $currentWeekEnd])
            ->get(['created_at'])
            ->each(function (FundPosting $posting) use (&$weekCounts): void {
                $createdAt = $posting->created_at;

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
