<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Services\MemberPortalInsightsService;
use App\Support\Tenant\CurrentMember;
use Filament\Widgets\Widget;

class MemberPortalDashboardWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.member.widgets.member-portal-dashboard';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $snapshot = app(MemberPortalInsightsService::class)->snapshot(CurrentMember::get());

        return [
            'd' => $snapshot,
            'currency' => $snapshot['currency'] ?? null,
        ];
    }
}
