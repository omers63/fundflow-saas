@php
    $hero = $hero ?? null;
    $kpis = $kpis ?? null;
    $sparkline = $sparkline ?? null;
    $sparklineMax = $sparklineMax ?? 1;
    $compact = (bool) ($compact ?? true);
    $title = $title ?? __('Overview');
    $subtitle = $subtitle ?? null;
    $badge = $badge ?? null;
    $wrapShell = (bool) ($wrapShell ?? false);
@endphp

{{-- Plain @include partial (not an anonymous Blade component): do not use @props/$attributes. --}}
@if ($wrapShell)
    @component('filament.tenant.partials.ops-overview.shell', [
        'title' => $title,
        'subtitle' => $subtitle,
        'badge' => $badge,
        'wrapperClass' => 'mb-0',
    ])
        @if (filled($hero))
            @include('filament.tenant.widgets.partials.insights-hero', [
                'hero' => $hero,
                'compact' => true,
            ])
        @endif

        @if (filled($kpis))
            @include('filament.tenant.widgets.partials.insights-kpi-strip', [
                'kpis' => $kpis,
                'sparkline' => $sparkline,
                'sparklineMax' => $sparklineMax,
                'compact' => true,
            ])
        @endif
    @endcomponent
@else
    <div @class([
        'ff-app-insights-head ff-ops-overview-head w-full space-y-3',
    ])>
        @if (filled($hero))
            @include('filament.tenant.widgets.partials.insights-hero', [
                'hero' => $hero,
                'compact' => true,
            ])
        @endif

        @if (filled($kpis))
            @include('filament.tenant.widgets.partials.insights-kpi-strip', [
                'kpis' => $kpis,
                'sparkline' => $sparkline,
                'sparklineMax' => $sparklineMax,
                'compact' => true,
            ])
        @endif
    </div>
@endif
