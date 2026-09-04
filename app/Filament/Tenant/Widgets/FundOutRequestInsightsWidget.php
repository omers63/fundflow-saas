<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Services\FundOutRequestInsightsService;
use Filament\Widgets\Widget;

class FundOutRequestInsightsWidget extends Widget
{
    /**
     * Registered only on the fund-outs list page, not the panel dashboard.
     */
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.fund-out-request-insights';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return app(FundOutRequestInsightsService::class)->snapshot();
    }
}
