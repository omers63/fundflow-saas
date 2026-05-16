<?php

declare(strict_types=1);

namespace App\Filament\Support;

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
}
