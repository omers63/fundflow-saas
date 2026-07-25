@php
    $hero = $hero ?? null;
    $kpis = $kpis ?? null;
    $sparkline = $sparkline ?? null;
    $sparklineMax = $sparklineMax ?? 1;
    $compact = (bool) ($compact ?? false);
@endphp
    
    {{-- Plain @include partial (not an anonymous Blade component): do not use @props/$attributes,
    or Livewire/Filament attribute bags with array values blow up in ComponentAttributeBag::toHtml(). --}}
    <div @class([
        'ff-app-insights-head w-full',
        'space-y-2' => $compact,
        'space-y-3' => !$compact,
    ])>
        @if (filled($hero))
            @include('filament.tenant.widgets.partials.insights-hero', [
                'hero' => $hero,
                'compact' => $compact,
            ])
        @endif
    
        @if (filled($kpis))
            @include('filament.tenant.widgets.partials.insights-kpi-strip', [
                'kpis' => $kpis,
                'sparkline' => $sparkline,
                'sparklineMax' => $sparklineMax,
                'compact' => $compact,
            ])
        @endif
</div>
