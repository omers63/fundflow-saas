<?php

namespace App\Filament\Widgets;

use App\Models\Central\Tenant;
use Carbon\CarbonPeriod;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class TenantGrowthChart extends ChartWidget
{
    protected ?string $heading = 'Tenant Growth';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user()->hasRole('super_admin');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $startDate = now()->subDays(29);
        $endDate = now();

        $tenants = Tenant::query()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->mapWithKeys(fn ($count, $date) => [Carbon::parse($date)->format('Y-m-d') => $count])
            ->all();

        $period = CarbonPeriod::create($startDate, '1 day', $endDate);
        $labels = [];
        $data = [];

        foreach ($period as $date) {
            $formattedDate = $date->format('Y-m-d');
            $labels[] = $date->format('M j');
            $data[] = Arr::get($tenants, $formattedDate, 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'New Tenants',
                    'data' => $data,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
