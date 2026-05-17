<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Services\TenantDashboardService;
use Filament\Widgets\Widget;

class TenantDashboardWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = -100;

    protected string $view = 'filament.tenant.widgets.tenant-dashboard';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return app(TenantDashboardService::class)->snapshot();
    }
}
