<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Services\MemberRequestInsightsService;
use Filament\Widgets\Widget;

class MemberRequestInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.member-request-insights';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return app(MemberRequestInsightsService::class)->snapshot();
    }
}
