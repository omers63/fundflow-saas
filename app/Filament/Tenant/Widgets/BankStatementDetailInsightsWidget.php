<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Models\Tenant\BankStatement;
use App\Services\BankStatementDetailInsightsService;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class BankStatementDetailInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.bank-statement-detail-insights';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public ?int $bankStatementId = null;

    public function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    #[On('refresh-bank-statement-detail-insights')]
    public function refreshWidget(int $bankStatementId): void
    {
        if ($this->bankStatementId === null || $bankStatementId !== $this->bankStatementId) {
            return;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        if ($this->bankStatementId === null) {
            return [];
        }

        $statement = BankStatement::query()->find($this->bankStatementId);

        if ($statement === null) {
            return [];
        }

        return app(BankStatementDetailInsightsService::class)->snapshot($statement);
    }
}
