<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Services\FundLifetimeTrendService;
use Filament\Widgets\Widget;

/**
 * Dashboard widget: the member lifetime chart (cycle bars, expected / due lines, max badges, pop-ups, cycle cards)
 * at fund level — every member's contributions, repayments and loan disbursements added together.
 */
class FundLifetimeTrendWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = true;

    protected static ?int $sort = -90;

    protected string $view = 'filament.tenant.widgets.fund-lifetime-trend';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'rows' => app(FundLifetimeTrendService::class)->trend(),
        ];
    }
}
