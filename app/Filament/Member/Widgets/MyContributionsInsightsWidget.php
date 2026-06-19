<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Services\MemberContributionInsightsService;
use App\Support\Tenant\CurrentMember;
use Filament\Widgets\Widget;

class MyContributionsInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.member.widgets.my-contributions-insights';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return app(MemberContributionInsightsService::class)->statCards(CurrentMember::get());
    }
}
