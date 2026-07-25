<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
use App\Services\LoanInsightsService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;

class LoanInsightsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = true;

    protected string $view = 'filament.tenant.widgets.loan-insights';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public string $context = 'portfolio';

    public ?string $selectedCycle = null;

    public ?string $queueTab = null;

    public Loan|int|null $record = null;

    public function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    #[On('refresh-loan-insights')]
    public function refreshInsights(?string $cycle = null, ?string $context = null): void
    {
        if (filled($cycle)) {
            $this->selectedCycle = $cycle;
        }

        if (filled($context)) {
            $this->context = $context;
        }
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

        $loan = $this->resolveLoan();

        $queueTab = $context === 'queue'
            ? (string) (request()->query('tab') ?? $this->queueTab ?? 'needs_decision')
            : $this->queueTab;

        $cycleKey = filled($this->selectedCycle)
            ? $this->selectedCycle
            : LoanResource::resolveListCycleKey();

        return app(LoanInsightsService::class)->forContext(
            $context,
            $loan,
            $queueTab,
            null,
            $cycleKey,
        );
    }

    protected function resolveLoan(): ?Loan
    {
        $loanId = match (true) {
            $this->record instanceof Loan => $this->record->getKey(),
            is_int($this->record) => $this->record,
            default => null,
        };

        if ($loanId === null) {
            return null;
        }

        return Loan::query()->find($loanId);
    }

    protected function resolveContext(): string
    {
        $route = Route::currentRouteName() ?? '';

        return match (true) {
            str_contains($route, 'loan-tiers') => 'loan_tiers',
            str_contains($route, 'fund-tiers') => 'fund_tiers',
            str_contains($route, 'loan-queue') => 'queue',
            str_contains($route, 'loans.delinquency') => 'delinquency',
            str_contains($route, 'loans.view'),
            str_contains($route, 'loans.edit') => 'loan_detail',
            default => filled($this->context) ? $this->context : 'portfolio',
        };
    }
}
