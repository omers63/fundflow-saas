<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Concerns\InteractsWithUnfoldedSections;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Services\ContributionInsightsService;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class ContributionInsightsWidget extends Widget
{
    use InteractsWithUnfoldedSections;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = true;

    protected string $view = 'filament.tenant.widgets.contribution-insights';

    protected int|string|array $columnSpan = 'full';

    public string $context = 'collect';

    public ?string $selectedCycle = null;

    #[On('refresh-contribution-insights')]
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
        return ContributionResource::resolveInsightsContext();
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $cycleKey = filled($this->selectedCycle)
            ? $this->selectedCycle
            : ContributionResource::resolveListCycleKey();

        return app(ContributionInsightsService::class)->forContext(
            $this->resolvedContext(),
            $cycleKey,
        );
    }
}
