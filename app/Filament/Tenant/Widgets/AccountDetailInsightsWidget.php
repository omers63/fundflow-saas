<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Models\Tenant\Account;
use App\Services\AccountDetailInsightsService;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class AccountDetailInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.account-detail-insights';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public ?int $accountId = null;

    public function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    #[On('refresh-account-detail-insights')]
    public function refreshWidget(int $accountId): void
    {
        if ($this->accountId === null || $accountId !== $this->accountId) {
            return;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        if ($this->accountId === null) {
            return [];
        }

        $account = Account::query()->find($this->accountId);

        if ($account === null) {
            return [];
        }

        return app(AccountDetailInsightsService::class)->snapshot($account);
    }
}
