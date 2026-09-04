@php
    $kpis = $this->getQueueInsightKpis();
@endphp

@if ($kpis !== [])
    @include('filament.tenant.widgets.partials.insights-kpi-strip', [
        'kpis' => $kpis,
        'compact' => true,
    ])
@endif
