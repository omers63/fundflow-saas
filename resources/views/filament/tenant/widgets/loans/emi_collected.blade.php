@include('filament.tenant.widgets.partials.insights-head', [
    'hero' => $d['hero'],
    'kpis' => $d['kpis'],
])

@include('filament.tenant.widgets.partials.cycle-collection-amount-stats', ['d' => $d])
