<?php

namespace App\Filament\Widgets;

use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    public static function canView(): bool
    {
        return auth()->user()->hasRole('super_admin');
    }

    protected function getStats(): array
    {
        $activeSubscriptions = Subscription::where('status', 'active');
        $totalRevenue = $activeSubscriptions->join('plans', 'subscriptions.plan_id', '=', 'plans.id')->sum('plans.price');

        return [
            Stat::make('Total Tenants', Tenant::count())
                ->description('All tenants in the system')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
            Stat::make('Active Subscriptions', $activeSubscriptions->count())
                ->description('Tenants with active plans')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
            Stat::make('Total Revenue', '$'.number_format($totalRevenue, 2))
                ->description('From active subscriptions')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
        ];
    }
}
