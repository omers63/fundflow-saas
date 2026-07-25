<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Tenant\MemberListTabService;
use App\Support\BusinessDay;
use App\Support\CollectionInsightsCache;
use App\Support\Insights\DualProgressTrendBuilder;

final class MemberInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return CollectionInsightsCache::remember(
            CollectionInsightsCache::DOMAIN_MEMBERS,
            'roster',
            fn (): array => $this->computeSnapshot(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeSnapshot(): array
    {
        $now = BusinessDay::now();
        $membersUrl = MemberResource::getUrl('index');
        $tabs = app(MemberListTabService::class)->tabCounts();
        $delinquency = app(LoanDelinquencyService::class);

        $total = (int) ($tabs['all'] ?? 0);
        $active = (int) ($tabs['active'] ?? 0);
        $inactive = (int) ($tabs['inactive'] ?? 0);
        $withdrawn = (int) ($tabs['withdrawn'] ?? 0);
        $migrationPending = (int) ($tabs['migration_pending'] ?? 0);

        // Align with Members "Arrears" tab / nav badge (outstanding contribution + EMI), not policy-only.
        $arrearsMemberIds = $delinquency->membersWithOutstandingArrearsIds();
        $arrearsCount = count($arrearsMemberIds);

        $newThisMonth = Member::query()
            ->whereMonth('joined_at', $now->month)
            ->whereYear('joined_at', $now->year)
            ->count();

        $lastMonth = $now->copy()->subMonthNoOverflow();
        $newLastMonth = Member::query()
            ->whereMonth('joined_at', $lastMonth->month)
            ->whereYear('joined_at', $lastMonth->year)
            ->count();

        $dependents = Member::query()->withParent()->count();

        $withActiveLoans = (int) Loan::query()
            ->where('status', 'active')
            ->selectRaw('COUNT(DISTINCT member_id) as aggregate')
            ->value('aggregate');

        $avgContribution = (float) (Member::query()->active()->avg('monthly_contribution_amount') ?? 0);

        $zeroCashMembers = Member::query()->activeWithZeroCash()->count();

        $attentionScope = function ($query) use ($arrearsMemberIds): void {
            if ($arrearsMemberIds !== []) {
                $query->whereIn('id', $arrearsMemberIds);
            } else {
                $query->whereRaw('0 = 1');
            }

            $query->orWhere('status', 'inactive')
                ->orWhereIn('id', Member::query()->activeWithZeroCash()->select('id'));
        };

        $needsAttention = Member::query()
            ->whereNot('status', 'withdrawn')
            ->where($attentionScope)
            ->count();

        $statusCounts = Member::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statusBreakdown = collect(Member::STATUSES)
            ->map(fn (string $status): array => [
                'status' => $status,
                'label' => Member::statusOptions()[$status] ?? ucfirst($status),
                'count' => (int) ($statusCounts[$status] ?? 0),
            ])
            ->values()
            ->all();

        $arrearsIdLookup = array_fill_keys($arrearsMemberIds, true);

        $attentionQueue = Member::query()
            ->whereNot('status', 'withdrawn')
            ->where($attentionScope)
            ->orderByRaw('CASE WHEN status = ? THEN 0 WHEN status = ? THEN 1 ELSE 2 END', ['active', 'inactive'])
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Member $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'status' => $member->adminStatusLabel(),
                'status_key' => $member->status,
                'has_arrears' => isset($arrearsIdLookup[(int) $member->id]),
                'contribution_amount' => (float) $member->monthly_contribution_amount,
                'view_url' => MemberResource::getUrl('view', ['record' => $member]),
            ])
            ->all();

        $currency = Setting::get('general', 'currency', 'USD');

        return [
            'total' => $total,
            'active' => $active,
            'delinquent' => $arrearsCount,
            'inactive' => $inactive,
            'withdrawn' => $withdrawn,
            'migration_pending' => $migrationPending,
            'needs_attention' => $needsAttention,
            'new_this_month' => $newThisMonth,
            'new_last_month' => $newLastMonth,
            'mom_change' => $this->monthOverMonthChange($newThisMonth, $newLastMonth),
            'dependents' => $dependents,
            'with_active_loans' => $withActiveLoans,
            'avg_contribution' => $avgContribution,
            'zero_cash_members' => $zeroCashMembers,
            'status_breakdown' => $statusBreakdown,
            'attention_queue' => $attentionQueue,
            'trend' => $this->sixMonthJoinTrend(),
            'sparkline' => $this->weeklyJoinSparkline(),
            'fund' => [
                'currency' => $currency,
                'avg_contribution' => $avgContribution,
                'active_loans' => Loan::query()->where('status', 'active')->count(),
                'zero_cash' => $zeroCashMembers,
            ],
            'pipeline' => [
                'active_members' => $active,
                'delinquent_members' => $arrearsCount,
                'dependents' => $dependents,
                'members_url' => $membersUrl,
                'members_active_url' => MemberResource::listUrl('all', ['status' => ['value' => 'active']]),
                'members_inactive_url' => MemberResource::listTabUrl('inactive'),
                'members_withdrawn_url' => MemberResource::listTabUrl('withdrawn'),
                'members_migration_url' => MemberResource::listTabUrl('migration_pending'),
                'members_arrears_url' => MemberResource::listTabUrl('delinquent'),
                'applications_url' => MembershipApplicationResource::getUrl('index'),
                'applications_pending_url' => MembershipApplicationResource::listTabUrl('pending'),
                'applications_approved_url' => MembershipApplicationResource::listTabUrl('approved'),
                'contributions_url' => ContributionResource::listUrl('contributions', ['status' => ['value' => 'pending']]),
                'delinquency_url' => MemberResource::listTabUrl('delinquent'),
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
     * @return list<array{label: string, total: int}>
     */
    private function sixMonthJoinTrend(): array
    {
        $now = BusinessDay::now();
        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();

        $monthCounts = Member::query()
            ->whereNotNull('joined_at')
            ->whereDate('joined_at', '>=', $oldestMonth)
            ->selectRaw("DATE_FORMAT(joined_at, '%Y-%m') as month_key, COUNT(*) as total")
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        $trend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth();
            $key = $month->format('Y-m');
            $total = (int) ($monthCounts[$key] ?? 0);

            $trend[] = [
                'label' => $month->format('M'),
                'total' => $total,
                'joined' => $total,
                'active' => 0,
                'other' => 0,
            ];
        }

        return DualProgressTrendBuilder::mapCountTrend($trend, 'total');
    }

    /**
     * @return list<int>
     */
    private function weeklyJoinSparkline(): array
    {
        $now = BusinessDay::now();
        $oldestWeekStart = $now->copy()->subWeeks(7)->startOfWeek();
        $currentWeekEnd = $now->copy()->endOfWeek();

        $weekCounts = Member::query()
            ->whereNotNull('joined_at')
            ->whereBetween('joined_at', [$oldestWeekStart, $currentWeekEnd])
            ->selectRaw('DATE(DATE_SUB(joined_at, INTERVAL WEEKDAY(joined_at) DAY)) as week_start, COUNT(*) as total')
            ->groupBy('week_start')
            ->pluck('total', 'week_start');

        $points = [];

        for ($i = 7; $i >= 0; $i--) {
            $start = $now->copy()->subWeeks($i)->startOfWeek()->toDateString();
            $points[] = (int) ($weekCounts[$start] ?? 0);
        }

        return $points;
    }
}
