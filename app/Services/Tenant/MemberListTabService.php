<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Member list status tabs.
 */
final class MemberListTabService
{
    /**
     * @return list<string>
     */
    public function tabKeys(): array
    {
        return [
            'all',
            'active',
            'inactive',
            'withdrawn',
            'delinquent',
            'migration_pending',
        ];
    }

    public function tabLabel(string $tab): string
    {
        return match ($tab) {
            'active' => __('Active'),
            'inactive' => __('Inactive'),
            'migration_pending' => __('Migration pending'),
            'delinquent' => __('Arrears'),
            'withdrawn' => __('Withdrawn'),
            default => __('All'),
        };
    }

    /**
     * @return array<string, int>
     */
    public function tabCounts(): array
    {
        return once(function (): array {
            $migrationPendingIds = $this->migrationPendingMemberIds();
            $delinquentIds = $this->delinquentMemberIds();

            return [
                'all' => Member::query()->count(),
                'active' => Member::query()->where('status', 'active')->count(),
                'inactive' => Member::query()->where('status', 'inactive')->count(),
                'withdrawn' => Member::query()->where('status', 'withdrawn')->count(),
                'delinquent' => count($delinquentIds),
                'migration_pending' => count($migrationPendingIds),
            ];
        });
    }

    public function applyTabFilter(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'active' => $query->where('members.status', 'active'),
            'inactive' => $query->where('members.status', 'inactive'),
            'withdrawn' => $query->where('members.status', 'withdrawn'),
            'migration_pending' => $query->whereIn('members.id', $this->migrationPendingMemberIds()),
            'delinquent' => $query
                ->where('members.status', 'active')
                ->whereIn('members.id', $this->delinquentMemberIds()),
            default => $query,
        };
    }

    /**
     * @return list<int>
     */
    public function migrationPendingMemberIds(): array
    {
        return once(function (): array {
            $delinquency = app(LoanDelinquencyService::class);

            return Member::query()
                ->where('status', 'active')
                ->whereNotNull('opening_balances_posted_at')
                ->orderBy('name')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $memberId): bool => $delinquency->countContributionArrearsPeriods($memberId) > 0)
                ->values()
                ->all();
        });
    }

    /**
     * @return list<int>
     */
    private function delinquentMemberIds(): array
    {
        return once(fn(): array => app(LoanDelinquencyService::class)->delinquentMemberIds());
    }

    /**
     * @return Collection<int, array{key: string, label: string, count: int, variant: string}>
     */
    public function pillTabs(): Collection
    {
        $counts = $this->tabCounts();

        return collect($this->tabKeys())->map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->tabLabel($key),
            'count' => $counts[$key] ?? 0,
            'variant' => match ($key) {
                'migration_pending' => 'violet',
                'inactive' => 'gray',
                'delinquent' => 'danger',
                'withdrawn' => 'danger',
                default => 'neutral',
            },
        ]);
    }
}
