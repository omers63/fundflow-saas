<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Member list status tabs (All / Active / Migration pending / Delinquent / Suspended).
 */
final class MemberListTabService
{
    /**
     * @return list<string>
     */
    public function tabKeys(): array
    {
        return ['all', 'active', 'migration_pending', 'delinquent', 'suspended'];
    }

    public function tabLabel(string $tab): string
    {
        return match ($tab) {
            'active' => __('Active'),
            'migration_pending' => __('Migration pending'),
            'delinquent' => __('Delinquent'),
            'suspended' => __('Suspended'),
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

            return [
                'all' => Member::query()->count(),
                'active' => Member::query()->where('status', 'active')->count(),
                'migration_pending' => count($migrationPendingIds),
                'delinquent' => Member::query()->where('status', 'delinquent')->count(),
                'suspended' => Member::query()->where('status', 'suspended')->count(),
            ];
        });
    }

    public function applyTabFilter(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'active' => $query->where('members.status', 'active'),
            'migration_pending' => $query->whereIn('members.id', $this->migrationPendingMemberIds()),
            'delinquent' => $query->where('members.status', 'delinquent'),
            'suspended' => $query->where('members.status', 'suspended'),
            default => $query,
        };
    }

    /**
     * Imported members with opening balances who still owe contribution arrears periods.
     *
     * @return list<int>
     */
    public function migrationPendingMemberIds(): array
    {
        return once(function (): array {
            $delinquency = app(LoanDelinquencyService::class);

            return Member::query()
                ->whereIn('status', ['active', 'delinquent'])
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
                'delinquent' => 'danger',
                'suspended' => 'warning',
                default => 'neutral',
            },
        ]);
    }
}
