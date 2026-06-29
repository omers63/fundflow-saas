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
use App\Support\BusinessDay;
use App\Support\Insights\DualProgressTrendBuilder;
use Carbon\Carbon;

final class MemberInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $now = BusinessDay::now();
        $membersUrl = MemberResource::getUrl('index');

        $total = Member::query()->count();
        $active = Member::query()->active()->count();
        $delinquency = app(LoanDelinquencyService::class);
        $delinquent = count($delinquency->delinquentMemberIds());
        $inactive = Member::query()->where('status', 'inactive')->count();
        $withdrawn = Member::query()->where('status', 'withdrawn')->count();

        $newThisMonth = Member::query()
            ->whereMonth('joined_at', $now->month)
            ->whereYear('joined_at', $now->year)
            ->count();

        $newLastMonth = Member::query()
            ->whereMonth('joined_at', $now->copy()->subMonth()->month)
            ->whereYear('joined_at', $now->copy()->subMonth()->year)
            ->count();

        $dependents = Member::query()->withParent()->count();
        $independent = Member::query()->independent()->count();

        $withActiveLoans = Member::query()
            ->whereHas('loans', fn ($query) => $query->where('status', 'active'))
            ->count();

        $loanExempt = Member::query()
            ->active()
            ->whereHas('loans', function ($query): void {
                $query->where('status', 'active')
                    ->whereHas('installments', fn ($installment) => $installment->whereIn('status', ['pending', 'overdue']));
            })
            ->count();

        $avgContribution = (float) (Member::query()->active()->avg('monthly_contribution_amount') ?? 0);

        $zeroCashMembers = Member::query()->activeWithZeroCash()->count();

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

        $delinquentMemberIds = $delinquency->delinquentMemberIds();

        $attentionQueue = Member::query()
            ->where(function ($query) use ($delinquentMemberIds): void {
                $query->whereIn('id', $delinquentMemberIds)
                    ->orWhere('status', 'inactive')
                    ->orWhere('status', 'withdrawn');
            })
            ->orderByRaw('CASE WHEN status = ? THEN 0 WHEN status = ? THEN 1 ELSE 2 END', ['active', 'inactive'])
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Member $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'status' => $member->adminStatusLabel(),
                'status_key' => $member->status,
                'has_arrears' => $delinquency->isDelinquent($member),
                'contribution_amount' => (float) $member->monthly_contribution_amount,
                'view_url' => MemberResource::getUrl('view', ['record' => $member]),
            ])
            ->all();

        $needsAttention = $delinquent + $inactive + $zeroCashMembers;

        $currency = Setting::get('general', 'currency', 'USD');

        return [
            'total' => $total,
            'active' => $active,
            'delinquent' => $delinquent,
            'inactive' => $inactive,
            'withdrawn' => $withdrawn,
            'needs_attention' => $needsAttention,
            'new_this_month' => $newThisMonth,
            'new_last_month' => $newLastMonth,
            'mom_change' => $this->monthOverMonthChange($newThisMonth, $newLastMonth),
            'dependents' => $dependents,
            'independent' => $independent,
            'with_active_loans' => $withActiveLoans,
            'loan_exempt' => $loanExempt,
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
                'loan_exempt' => $loanExempt,
                'zero_cash' => $zeroCashMembers,
            ],
            'pipeline' => [
                'active_members' => $active,
                'delinquent_members' => $delinquent,
                'dependents' => $dependents,
                'members_url' => $membersUrl,
                'members_active_url' => MemberResource::listUrl('all', ['status' => ['value' => 'active']]),
                'members_inactive_url' => MemberResource::listTabUrl('inactive'),
                'members_withdrawn_url' => MemberResource::listTabUrl('withdrawn'),
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
        $monthCounts = [];

        Member::query()
            ->whereNotNull('joined_at')
            ->whereDate('joined_at', '>=', $oldestMonth)
            ->get(['joined_at'])
            ->each(function (Member $member) use (&$monthCounts): void {
                $joinedAt = $member->joined_at;

                if ($joinedAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $joinedAt)->startOfMonth()->format('Y-m');
                $monthCounts[$key] = ($monthCounts[$key] ?? 0) + 1;
            });

        $trend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth();
            $key = $month->format('Y-m');
            $total = $monthCounts[$key] ?? 0;

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
        $weekCounts = [];

        Member::query()
            ->whereNotNull('joined_at')
            ->whereBetween('joined_at', [$oldestWeekStart, $currentWeekEnd])
            ->get(['joined_at'])
            ->each(function (Member $member) use (&$weekCounts): void {
                $joinedAt = $member->joined_at;

                if ($joinedAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $joinedAt)->startOfWeek()->toDateString();
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
