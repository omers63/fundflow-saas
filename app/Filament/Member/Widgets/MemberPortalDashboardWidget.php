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

    protected ?string $pollingInterval = '60s';

    public function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return app(MemberPortalInsightsService::class)->snapshot(CurrentMember::get());
    }
}
