<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\Setting;
use Carbon\Carbon;

final class MembershipApplicationInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $now = Carbon::now();

        $pending = MembershipApplication::query()->where('status', 'pending')->count();
        $approved = MembershipApplication::query()->where('status', 'approved')->count();
        $rejected = MembershipApplication::query()->where('status', 'rejected')->count();
        $total = $pending + $approved + $rejected;

        $newThisMonth = MembershipApplication::query()
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->count();

        $newLastMonth = MembershipApplication::query()
            ->whereMonth('created_at', $now->copy()->subMonth()->month)
            ->whereYear('created_at', $now->copy()->subMonth()->year)
            ->count();

        $approvedThisMonth = MembershipApplication::query()
            ->where('status', 'approved')
            ->whereMonth('reviewed_at', $now->month)
            ->whereYear('reviewed_at', $now->year)
            ->count();

        $rejectedThisMonth = MembershipApplication::query()
            ->where('status', 'rejected')
            ->whereMonth('reviewed_at', $now->month)
            ->whereYear('reviewed_at', $now->year)
            ->count();

        $decided = $approved + $rejected;
        $approvalRate = $decided > 0 ? round(($approved / $decided) * 100, 1) : null;

        $reviewedApplications = MembershipApplication::query()
            ->whereIn('status', ['approved', 'rejected'])
            ->whereNotNull('reviewed_at')
            ->get(['created_at', 'reviewed_at']);

        $avgReviewDays = $reviewedApplications->isEmpty()
            ? 0.0
            : round((float) $reviewedApplications->avg(
                fn (MembershipApplication $application): float => (float) Carbon::parse($application->created_at)
                    ->diffInDays(Carbon::parse($application->reviewed_at))
            ), 1);

        $pendingOverSla = MembershipApplication::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $now->copy()->subDays(7))
            ->count();

        $subscriptionFees = app(MembershipSubscriptionFeeService::class);

        $oldestPending = MembershipApplication::query()
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit(6)
            ->get()
            ->map(fn (MembershipApplication $application): array => [
                'id' => $application->id,
                'name' => $application->name,
                'email' => $application->email,
                'days_waiting' => (int) Carbon::parse($application->created_at)->diffInDays($now),
                'type' => $application->application_type ?? 'new',
                'has_receipt' => $subscriptionFees->applicationRequiresSubscriptionFee($application)
                    ? filled($application->membership_fee_receipt_path)
                    : filled($application->application_form_path),
                'edit_url' => MembershipApplicationResource::getUrl('edit', ['record' => $application]),
            ])
            ->all();

        $typeCounts = MembershipApplication::query()
            ->selectRaw('application_type, COUNT(*) as total')
            ->groupBy('application_type')
            ->pluck('total', 'application_type');

        $typeBreakdown = collect(MembershipApplication::APPLICATION_TYPES)
            ->map(fn (string $type): array => [
                'type' => $type,
                'label' => ucfirst($type),
                'count' => (int) ($typeCounts[$type] ?? 0),
            ])
            ->values()
            ->all();

        $pendingFeeTotal = (float) MembershipApplication::query()
            ->where('status', 'pending')
            ->whereNotNull('membership_fee_amount')
            ->where('membership_fee_amount', '>', 0)
            ->sum('membership_fee_amount');

        $pendingWithFeeCount = MembershipApplication::query()
            ->where('status', 'pending')
            ->whereNotNull('membership_fee_amount')
            ->where('membership_fee_amount', '>', 0)
            ->count();
        $pendingWithReceipt = MembershipApplication::query()
            ->where('status', 'pending')
            ->whereNotNull('application_form_path')
            ->where('application_form_path', '!=', '')
            ->count();

        $receiptRate = $pending > 0 ? (int) round(($pendingWithReceipt / $pending) * 100) : 0;

        $activeMembers = Member::query()->active()->count();
        $membersJoinedThisMonth = Member::query()
            ->whereMonth('joined_at', $now->month)
            ->whereYear('joined_at', $now->year)
            ->count();

        $currency = Setting::get('general', 'currency', 'USD');

        return [
            'total' => $total,
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'new_this_month' => $newThisMonth,
            'new_last_month' => $newLastMonth,
            'mom_change' => $this->monthOverMonthChange($newThisMonth, $newLastMonth),
            'approved_this_month' => $approvedThisMonth,
            'rejected_this_month' => $rejectedThisMonth,
            'approval_rate' => $approvalRate,
            'avg_review_days' => $avgReviewDays,
            'pending_over_sla' => $pendingOverSla,
            'oldest_pending' => $oldestPending,
            'trend' => $this->sixMonthTrend(),
            'sparkline' => $this->weeklySparkline(),
            'type_breakdown' => $typeBreakdown,
            'fees' => [
                'currency' => $currency,
                'pending_total' => $pendingFeeTotal,
                'pending_with_fee' => $pendingWithFeeCount,
                'receipt_rate' => $receiptRate,
            ],
            'pipeline' => [
                'pending_apps' => $pending,
                'approved_apps' => $approved,
                'active_members' => $activeMembers,
                'members_joined_month' => $membersJoinedThisMonth,
                'applications_url' => MembershipApplicationResource::getUrl('index'),
                'applications_pending_url' => MembershipApplicationResource::listUrl(['status' => ['value' => 'pending']]),
                'applications_approved_url' => MembershipApplicationResource::listUrl(['status' => ['value' => 'approved']]),
                'members_url' => MemberResource::getUrl('index'),
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
     * @return list<array{label: string, total: int, approved: int, rejected: int, pending: int}>
     */
    private function sixMonthTrend(): array
    {
        $now = Carbon::now();
        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();
        $monthTotals = [];

        MembershipApplication::query()
            ->whereBetween('created_at', [$oldestMonth, $now->copy()->endOfMonth()])
            ->get(['status', 'created_at'])
            ->each(function (MembershipApplication $application) use (&$monthTotals): void {
                $createdAt = $application->created_at;

                if ($createdAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $createdAt)->startOfMonth()->format('Y-m');
                $monthTotals[$key] ??= [
                    'total' => 0,
                    'approved' => 0,
                    'rejected' => 0,
                    'pending' => 0,
                ];
                $monthTotals[$key]['total']++;

                if ($application->status === 'approved') {
                    $monthTotals[$key]['approved']++;

                    return;
                }

                if ($application->status === 'rejected') {
                    $monthTotals[$key]['rejected']++;

                    return;
                }

                if ($application->status === 'pending') {
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
                'approved' => (int) ($monthTotals[$key]['approved'] ?? 0),
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

        MembershipApplication::query()
            ->whereBetween('created_at', [$oldestWeekStart, $currentWeekEnd])
            ->get(['created_at'])
            ->each(function (MembershipApplication $application) use (&$weekCounts): void {
                $createdAt = $application->created_at;

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
