@props([
    'hero' => null,
    'kpis' => null,
    'sparkline' => null,
    'sparklineMax' => 1,
])

<div {{ $attributes->merge(['class' => 'ff-app-insights-head w-full space-y-3']) }}>
    @if (filled($hero))
        @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $hero])
    @endif

    @if (filled($kpis))
        @include('filament.tenant.widgets.partials.insights-kpi-strip', [
            'kpis' => $kpis,
            'sparkline' => $sparkline,
            'sparklineMax' => $sparklineMax,
        ])
    @endif
</div>
