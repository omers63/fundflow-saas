@php
    use App\Filament\Support\MoneyDisplay;

    $pipeline = $d['pipeline'];
    $open = $d['open_period'];

    $hero = $d['pending'] > 0
        ? [
            'title' => __('Contributions need your attention'),
            'subtitle' => trans_choice(':count pending', $d['pending'], ['count' => $d['pending']])
                . ' · ' . (MoneyDisplay::format($d['pending_amount_total'], $d['cycle']['currency'], precision: 0) ?? '')
                . ($d['late_count'] > 0 ? ' · ' . trans_choice(':count late', $d['late_count'], ['count' => $d['late_count']]) : ''),
            'tone' => 'amber',
            'cta_url' => $pipeline['contributions_pending_url'],
            'cta_label' => __('Review'),
        ]
        : ['title' => __('Cycle on track'), 'subtitle' => __('No pending contributions to review'), 'tone' => 'success'];

    $kpis = \App\Support\Insights\InsightKpi::linkMany([
        ['key' => 'pending', 'label' => __('Pending'), 'value' => $d['pending'], 'sub' => __('Awaiting post'), 'icon' => 'heroicon-o-clock', 'accent' => 'amber', 'active' => $d['pending'] > 0],
        ['key' => 'posted', 'label' => __('Posted'), 'value' => $d['posted'], 'sub' => __(':count/mo', ['count' => $d['posted_this_month']]), 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true],
        ['key' => 'failed', 'label' => __('Failed'), 'value' => $d['failed'], 'sub' => __('All time'), 'icon' => 'heroicon-o-x-circle', 'accent' => 'rose', 'active' => $d['failed'] > 0],
        ['key' => 'arrears', 'label' => __('Arrears'), 'value' => $pipeline['arrears_periods'] ?? 0, 'sub' => __('Periods'), 'icon' => 'heroicon-o-exclamation-triangle', 'accent' => 'rose', 'active' => ($pipeline['arrears_periods'] ?? 0) > 0],
        ['key' => 'rate', 'label' => __('Collection'), 'value' => $open['collection_rate'] . '%', 'sub' => $open['label'], 'icon' => 'heroicon-o-chart-pie', 'accent' => 'violet', 'active' => true],
        ['key' => 'late', 'label' => __('Late'), 'value' => $d['late_count'], 'sub' => __('Pending'), 'icon' => 'heroicon-o-exclamation-triangle', 'accent' => 'teal', 'active' => $d['late_count'] > 0],
    ], [
        'pending' => $pipeline['contributions_pending_url'],
        'posted' => $pipeline['contributions_posted_url'],
        'failed' => $pipeline['contributions_failed_url'],
        'arrears' => $pipeline['arrears_url'],
        'rate' => $pipeline['cycle_url'],
        'late' => $pipeline['contributions_pending_url'],
    ]);
@endphp

@include('filament.tenant.widgets.partials.insights-head', [
    'hero' => $hero,
    'kpis' => $kpis,
])
