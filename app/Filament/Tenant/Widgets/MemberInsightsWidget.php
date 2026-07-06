<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Services\MemberInsightsService;
use Filament\Widgets\Widget;

class MemberInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = true;

    protected string $view = 'filament.tenant.widgets.member-insights';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return app(MemberInsightsService::class)->snapshot();
    }
}
