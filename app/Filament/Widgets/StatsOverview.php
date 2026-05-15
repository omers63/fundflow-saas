<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Tenants\TenantResource;
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
        $activeSubscriptionsBase = Subscription::query()->where('status', 'active');
        $activeSubscriptionsCount = (clone $activeSubscriptionsBase)->count();
        $totalRevenue = (clone $activeSubscriptionsBase)
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->sum('plans.price');

        return [
            Stat::make(__('Total Tenants'), Tenant::count())
                ->description(__('All tenants in the system'))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->url(TenantResource::getUrl('index')),
            Stat::make(__('Active Subscriptions'), $activeSubscriptionsCount)
                ->description(__('Tenants with active plans'))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->url(SubscriptionResource::getUrl('index')),
            Stat::make(__('Total Revenue'), '$'.number_format((float) $totalRevenue, 2))
                ->description(__('From active subscriptions'))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->url(SubscriptionResource::getUrl('index')),
        ];
    }
}
