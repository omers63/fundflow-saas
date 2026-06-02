<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Account;
use Livewire\Component;
use Livewire\Livewire;

final class AccountDetailInsightsRefresh
{
    public static function dispatch(Component $livewire, int $accountId): void
    {
        $livewire->dispatch('refresh-account-detail-insights', accountId: $accountId);
    }

    public static function dispatchForAccount(int $accountId): void
    {
        $component = Livewire::current();

        if ($component instanceof Component) {
            self::dispatch($component, $accountId);
        }
    }

    /**
     * Refresh account insights and, when the account belongs to a member, member profile insights too.
     */
    public static function dispatchLedgerChange(int $accountId): void
    {
        self::dispatchForAccount($accountId);

        $component = Livewire::current();

        if (! $component instanceof Component) {
            return;
        }

        $memberId = Account::query()->whereKey($accountId)->value('member_id');

        if ($memberId === null) {
            return;
        }

        MemberResource::dispatchMemberDetailInsightsRefresh($component);
        $component->dispatch('refresh-member-detail-insights', memberId: (int) $memberId);
    }

    /**
     * @param  list<int>  $accountIds
     */
    public static function dispatchLedgerChangeForAccounts(array $accountIds): void
    {
        $accountIds = array_values(array_unique(array_map(intval(...), $accountIds)));

        if ($accountIds === []) {
            return;
        }

        foreach ($accountIds as $accountId) {
            self::dispatchForAccount($accountId);
        }

        $component = Livewire::current();

        if (! $component instanceof Component) {
            return;
        }

        $memberIds = Account::query()
            ->whereIn('id', $accountIds)
            ->whereNotNull('member_id')
            ->pluck('member_id')
            ->unique()
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($memberIds === []) {
            return;
        }

        MemberResource::dispatchMemberDetailInsightsRefresh($component);

        foreach ($memberIds as $memberId) {
            $component->dispatch('refresh-member-detail-insights', memberId: $memberId);
        }
    }
}
