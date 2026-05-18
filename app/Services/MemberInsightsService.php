<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Support\Insights\InsightFormatter;
use Carbon\Carbon;

final class MemberInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $now = Carbon::now();
        $membersUrl = MemberResource::getUrl('index');

        $total = Member::query()->count();
        $active = Member::query()->active()->count();
        $delinquent = Member::query()->where('status', 'delinquent')->count();
        $suspended = Member::query()->where('status', 'suspended')->count();
        $withdrawn = Member::query()->where('status', 'withdrawn')->count();
        $terminated = Member::query()->where('status', 'terminated')->count();
        $inactive = $suspended + $withdrawn + $terminated;

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

        $zeroCashMembers = Member::query()
            ->active()
            ->whereHas('accounts', fn ($query) => $query
                ->where('type', 'cash')
                ->where('is_master', false)
                ->where('balance', '<=', 0))
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

        $attentionQueue = Member::query()
            ->whereIn('status', ['delinquent', 'suspended'])
            ->orderByRaw("CASE WHEN status = 'delinquent' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Member $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'status' => Member::statusOptions()[$member->status] ?? $member->status,
                'status_key' => $member->status,
                'contribution' => InsightFormatter::money((float) $member->monthly_contribution_amount),
                'view_url' => MemberResource::getUrl('edit', ['record' => $member]),
            ])
            ->all();

        $needsAttention = $delinquent + $suspended + $zeroCashMembers;

        $currency = Setting::get('general', 'currency', 'USD');

        return [
            'total' => $total,
            'active' => $active,
            'delinquent' => $delinquent,
            'suspended' => $suspended,
            'inactive' => $inactive,
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
                'applications_url' => MembershipApplicationResource::getUrl('index'),
                'contributions_url' => ContributionResource::getUrl('index').'?tableFilters[status][value]=pending',
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
     * @return list<array{label: string, total: int}>
     */
    private function sixMonthJoinTrend(): array
    {
        $trend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();

            $total = Member::query()
                ->whereNotNull('joined_at')
                ->whereYear('joined_at', $month->year)
                ->whereMonth('joined_at', $month->month)
                ->count();

            $trend[] = [
                'label' => $month->format('M'),
                'total' => $total,
                'joined' => $total,
                'active' => 0,
                'other' => 0,
            ];
        }

        return $trend;
    }

    /**
     * @return list<int>
     */
    private function weeklyJoinSparkline(): array
    {
        $points = [];

        for ($i = 7; $i >= 0; $i--) {
            $start = Carbon::now()->subWeeks($i)->startOfWeek();
            $end = $start->copy()->endOfWeek();

            $points[] = Member::query()
                ->whereBetween('joined_at', [$start, $end])
                ->count();
        }

        return $points;
    }
}
