<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Services\BankAccountsInsightsService;
use Filament\Widgets\Widget;

class BankAccountsInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.bank-accounts-insights';

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
        return app(BankAccountsInsightsService::class)->snapshot();
    }
}
