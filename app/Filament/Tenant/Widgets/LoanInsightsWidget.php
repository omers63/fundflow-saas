<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Models\Tenant\Loan;
use App\Services\LoanInsightsService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Route;

class LoanInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.loan-insights';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public string $context = 'portfolio';

    public ?string $queueTab = null;

    public Loan|int|null $record = null;

    public function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    public function resolvedContext(): string
    {
        return $this->resolveContext();
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $context = $this->resolveContext();

        $loan = $this->record instanceof Loan
            ? $this->record
            : (is_int($this->record) ? Loan::query()->find($this->record) : null);

        $queueTab = $context === 'queue'
            ? (string) (request()->query('tab') ?? $this->queueTab ?? 'needs_decision')
            : $this->queueTab;

        return app(LoanInsightsService::class)->forContext(
            $context,
            $loan,
            $queueTab,
        );
    }

    protected function resolveContext(): string
    {
        $route = Route::currentRouteName() ?? '';

        return match (true) {
            str_contains($route, 'loan-tiers') => 'loan_tiers',
            str_contains($route, 'fund-tiers') => 'fund_tiers',
            str_contains($route, 'loans.queue') => 'queue',
            str_contains($route, 'loans.delinquency') => 'delinquency',
            str_contains($route, 'loans.view'),
            str_contains($route, 'loans.edit') => 'loan_detail',
            default => filled($this->context) ? $this->context : 'portfolio',
        };
    }
}
