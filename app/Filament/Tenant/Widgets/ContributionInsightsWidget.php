<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Services\ContributionInsightsService;
use Filament\Widgets\Widget;

class ContributionInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.contribution-insights';

    protected int|string|array $columnSpan = 'full';

    public string $context = 'collect';

    public function resolvedContext(): string
    {
        return ContributionResource::resolveListTab();
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return app(ContributionInsightsService::class)->forContext($this->resolvedContext());
    }
}
