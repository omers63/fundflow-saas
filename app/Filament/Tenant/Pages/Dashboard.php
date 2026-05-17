<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Tenant\Widgets\TenantDashboardWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;

class Dashboard extends BaseDashboard
{
    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fi-page-tenant-dashboard'];
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            TenantDashboardWidget::class,
        ];
    }

    /**
     * @return int|array<string, int|null>
     */
    public function getColumns(): int|array
    {
        return 1;
    }
}
