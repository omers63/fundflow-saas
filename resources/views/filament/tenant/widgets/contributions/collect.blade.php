@include('filament.tenant.widgets.partials.insights-head', [
    'hero' => $d['hero'],
    'kpis' => $d['kpis'],
    'compact' => $compact ?? false,
])

@include('filament.tenant.widgets.partials.cycle-collection-amount-stats', [
    'd' => $d,
    'compact' => $compact ?? false,
])

@if (method_exists($this, 'isSectionUnfolded'))
    <x-ff-lazy-fold
        section="value_chart_collection"
        :unfolded="$this->isSectionUnfolded('value_chart_collection')"
        :title="__('Value chart · Cycle mix')"
        :hint="__('Expand to load collection composition (cached).')"
        class="mt-2"
    >
        @if ($this->isSectionUnfolded('value_chart_collection'))
            @include('filament.partials.insights.value-chart', [
                'chart' => app(\App\Services\ValueChartsService::class)->collectionCycleComposition(
                    property_exists($this, 'selectedCycle') ? $this->selectedCycle : null,
                ),
            ])
        @endif
    </x-ff-lazy-fold>
@endif
