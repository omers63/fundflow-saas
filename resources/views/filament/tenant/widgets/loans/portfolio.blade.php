@include('filament.tenant.widgets.partials.insights-head', [
    'hero' => $d['hero'],
    'kpis' => $d['kpis'],
    'compact' => $compact ?? false,
])

@if (method_exists($this, 'isSectionUnfolded'))
    <x-ff-lazy-fold
        section="value_chart_portfolio"
        :unfolded="$this->isSectionUnfolded('value_chart_portfolio')"
        :title="__('Value chart · Portfolio mix')"
        :hint="__('Expand to load loan portfolio composition (cached).')"
        class="mt-2"
    >
        @if ($this->isSectionUnfolded('value_chart_portfolio'))
            @include('filament.partials.insights.value-chart', [
                'chart' => app(\App\Services\ValueChartsService::class)->loanPortfolioComposition(),
            ])
        @endif
    </x-ff-lazy-fold>
@endif
