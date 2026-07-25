@include('filament.tenant.widgets.partials.insights-head', [
    'hero' => $d['hero'],
    'kpis' => $d['kpis'],
    'compact' => $compact ?? false,
])

@include('filament.tenant.widgets.partials.cycle-collection-amount-stats', [
    'd' => $d,
    'compact' => $compact ?? false,
])
