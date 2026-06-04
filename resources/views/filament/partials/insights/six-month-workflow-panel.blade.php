@include('filament.partials.insights.six-month-dual-progress-panel', [
    'title' => $title,
    'trend' => $trend,
    'primaryLabel' => $primaryLabel ?? __('Success'),
    'secondaryLabel' => $secondaryLabel ?? __('Decided'),
])
