@php
    $d = $this->getData();
    $currency = $d['currency'];

    $badge = match ($d['hero']['tone'] ?? null) {
        'amber' => ['label' => __('Needs attention'), 'tone' => 'amber'],
        'success' => ['label' => $d['hero']['title'], 'tone' => 'success'],
        default => null,
    };

    $kpis = \App\Support\Insights\InsightKpi::linkMany([
        [
            'key' => 'pending',
            'label' => __('Pending'),
            'value' => $d['pending_amount'],
            'value_is_amount' => true,
            'currency' => $currency,
            'value_precision' => 0,
            'sub' => trans_choice(':count requests', $d['pending'], ['count' => $d['pending']]),
            'accent' => 'amber',
            'active' => $d['pending'] > 0,
        ],
        [
            'key' => 'accepted',
            'label' => __('Accepted'),
            'value' => $d['accepted_amount'],
            'value_is_amount' => true,
            'currency' => $currency,
            'value_precision' => 0,
            'sub' => trans_choice(':count requests', $d['accepted'], ['count' => $d['accepted']]),
            'accent' => 'emerald',
            'active' => true,
        ],
        [
            'key' => 'rejected',
            'label' => __('Rejected'),
            'value' => $d['rejected'],
            'sub' => __('decided'),
            'accent' => 'rose',
            'active' => $d['rejected'] > 0,
        ],
        [
            'key' => 'total',
            'label' => __('Total'),
            'value' => $d['total'],
            'sub' => __('all statuses'),
            'accent' => 'sky',
            'active' => true,
        ],
    ], [
        'pending' => $d['pending_url'],
        'accepted' => $d['accepted_url'],
        'rejected' => $d['rejected_url'],
        'total' => $d['index_url'],
    ]);
@endphp

@component('filament.tenant.partials.ops-overview.shell', [
    'title' => __('Overview'),
    'badge' => $badge,
    'wrapperClass' => '',
])
    @include('filament.tenant.widgets.partials.insights-head', [
        'hero' => $d['hero'],
        'kpis' => $kpis,
    ])

    @include('filament.tenant.partials.ops-overview.links', [
        'links' => [
            ['label' => __('Pending'), 'url' => $d['pending_url']],
            ['label' => __('Accepted'), 'url' => $d['accepted_url']],
            ['label' => __('All fund-outs'), 'url' => $d['index_url']],
        ],
    ])
@endcomponent
