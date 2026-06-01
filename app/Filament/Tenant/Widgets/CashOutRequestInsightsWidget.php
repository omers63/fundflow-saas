<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Services\CashOutRequestInsightsService;
use Filament\Widgets\Widget;

class CashOutRequestInsightsWidget extends Widget
{
    /**
     * Registered only on the cash-outs list page, not the panel dashboard.
     */
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.cash-out-request-insights';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return app(CashOutRequestInsightsService::class)->snapshot();
    }
}
