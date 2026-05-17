<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Services\MonthlyStatementInsightsService;
use App\Support\Tenant\CurrentMember;
use Filament\Widgets\Widget;

class MemberMonthlyStatementInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.member.widgets.member-monthly-statement-insights';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $memberId = CurrentMember::id();

        return app(MonthlyStatementInsightsService::class)->memberSnapshot($memberId);
    }
}
