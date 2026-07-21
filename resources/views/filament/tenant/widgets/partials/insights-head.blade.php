@php
    $hero = $hero ?? null;
    $kpis = $kpis ?? null;
    $sparkline = $sparkline ?? null;
    $sparklineMax = $sparklineMax ?? 1;
@endphp

{{-- Plain @include partial (not an anonymous Blade component): do not use @props/$attributes,
or Livewire/Filament attribute bags with array values blow up in ComponentAttributeBag::toHtml(). --}}
<div class="ff-app-insights-head w-full space-y-3">
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
