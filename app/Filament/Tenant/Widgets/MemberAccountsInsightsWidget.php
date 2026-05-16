<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Services\MemberAccountsInsightsService;
use Filament\Widgets\Widget;

class MemberAccountsInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.member-accounts-insights';

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
        return app(MemberAccountsInsightsService::class)->snapshot();
    }
}
