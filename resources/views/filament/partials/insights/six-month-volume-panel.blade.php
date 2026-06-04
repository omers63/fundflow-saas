@include('filament.partials.insights.six-month-dual-progress-panel', [
    'title' => $title,
    'trend' => $trend,
    'primaryLabel' => $primaryLabel ?? __('Credits'),
    'secondaryLabel' => $secondaryLabel ?? __('Debits'),
    'compact' => $compact ?? false,
])
