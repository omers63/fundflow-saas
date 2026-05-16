<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Services\MembershipApplicationInsightsService;
use Filament\Widgets\Widget;

class MembershipApplicationInsightsWidget extends Widget
{
    /**
     * Registered only on the applications list page, not the panel dashboard.
     */
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.membership-application-insights';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '5s';

    public function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return app(MembershipApplicationInsightsService::class)->snapshot();
    }
}
