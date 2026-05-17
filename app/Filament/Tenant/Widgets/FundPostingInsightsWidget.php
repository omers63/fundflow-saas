<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Services\FundPostingInsightsService;
use Filament\Widgets\Widget;

class FundPostingInsightsWidget extends Widget
{
    /**
     * Registered only on the deposits list page, not the panel dashboard.
     */
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.fund-posting-insights';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return app(FundPostingInsightsService::class)->snapshot();
    }
}
