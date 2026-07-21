<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Services\BankAccountsInsightsService;
use Filament\Widgets\Widget;

class BankAccountsInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.bank-accounts-insights';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    /** Forced by the Work queue balances toggle; ignores request/Livewire parent resolution. */
    public string $activeTab = BankClearingTabRegistry::TAB_QUEUE;

    public function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return app(BankAccountsInsightsService::class)->snapshot($this->activeTab);
    }
}
