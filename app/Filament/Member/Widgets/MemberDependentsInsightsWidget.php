<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Services\MemberDependentsInsightsService;
use Filament\Widgets\Widget;

class MemberDependentsInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.member.widgets.member-dependents-insights';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return app(MemberDependentsInsightsService::class)->snapshot();
    }
}
