@php
use App\Filament\Tenant\Resources\Members\MemberResource;

$d = $this->getData();
$pipeline = $d['pipeline'];
$currency = $d['fund']['currency'];
$hasArrears = ($d['delinquent'] ?? 0) > 0;
$hasInactive = ($d['inactive'] ?? 0) > 0;
$needsAttention = ($d['needs_attention'] ?? 0) > 0;

$badge = $needsAttention
    ? [
        'label' => __('Needs attention'),
        'tone' => 'amber',
    ]
    : [
        'label' => __('Roster healthy'),
        'tone' => 'success',
    ];

$hero = $needsAttention
    ? [
        'title' => __('Members need your attention'),
        'subtitle' => collect([
            $hasArrears ? trans_choice(':count with arrears|:count with arrears', $d['delinquent'], ['count' => $d['delinquent']]) : null,
            $hasInactive ? trans_choice(':count inactive|:count inactive', $d['inactive'], ['count' => $d['inactive']]) : null,
        ])->filter()->implode(' · '),
        'tone' => 'amber',
        'cta_url' => $hasArrears
            ? ($pipeline['members_arrears_url'] ?? MemberResource::listTabUrl('delinquent'))
            : ($pipeline['members_inactive_url'] ?? MemberResource::listTabUrl('inactive')),
        'cta_label' => $hasArrears ? __('Review arrears') : __('Review inactive'),
    ]
    : [
        'title' => __('Roster healthy'),
        'subtitle' => __('No inactive members or arrears right now.'),
        'tone' => 'success',
    ];

$kpiItems = [
    [
        'label' => __('Active'),
        'value' => number_format((int) $d['active']),
        'url' => $pipeline['members_active_url'] ?? null,
        'value_class' => 'mt-0.5 text-lg font-extrabold tabular-nums tracking-tight text-emerald-600 sm:text-xl dark:text-emerald-400',
    ],
    [
        'label' => __('Inactive'),
        'value' => number_format((int) $d['inactive']),
        'url' => $pipeline['members_inactive_url'] ?? MemberResource::listTabUrl('inactive'),
        'value_class' => 'mt-0.5 text-lg font-extrabold tabular-nums tracking-tight text-gray-700 sm:text-xl dark:text-gray-200',
    ],
    [
        'label' => __('Withdrawn'),
        'value' => number_format((int) $d['withdrawn']),
        'url' => $pipeline['members_withdrawn_url'] ?? MemberResource::listTabUrl('withdrawn'),
        'value_class' => 'mt-0.5 text-lg font-extrabold tabular-nums tracking-tight text-rose-600 sm:text-xl dark:text-rose-400',
    ],
    [
        'label' => __('Migration'),
        'value' => number_format((int) ($d['migration_pending'] ?? 0)),
        'url' => $pipeline['members_migration_url'] ?? MemberResource::listTabUrl('migration_pending'),
        'value_class' => 'mt-0.5 text-lg font-extrabold tabular-nums tracking-tight text-violet-600 sm:text-xl dark:text-violet-400',
    ],
];

$chips = [
    [
        'label' => __(':count total', ['count' => $d['total']]),
        'tone' => 'gray',
    ],
    [
        'label' => __(':count with arrears', ['count' => $d['delinquent']]),
        'url' => $pipeline['members_arrears_url'] ?? MemberResource::listTabUrl('delinquent'),
        'tone' => 'amber',
    ],
    [
        'label' => __(':count dependents', ['count' => $d['dependents']]),
        'tone' => 'gray',
    ],
    [
        'label' => __(':count on loans', ['count' => $d['with_active_loans']]),
        'tone' => 'gray',
    ],
];

if (($d['avg_contribution'] ?? 0) > 0) {
    $chips[] = [
        'label' => __('Avg :amount/mo', [
            'amount' => \App\Support\Insights\InsightFormatter::money((float) $d['avg_contribution']),
        ]),
        'tone' => 'sky',
    ];
}

$links = array_values(array_filter([
    [
        'label' => __('Active'),
        'url' => $pipeline['members_active_url'] ?? null,
    ],
    [
        'label' => __('Arrears'),
        'url' => $pipeline['members_arrears_url'] ?? MemberResource::listTabUrl('delinquent'),
    ],
    [
        'label' => __('Inactive'),
        'url' => $pipeline['members_inactive_url'] ?? MemberResource::listTabUrl('inactive'),
    ],
]));
@endphp

@component('filament.tenant.partials.ops-overview.shell', [
    'title' => __('Overview'),
    'badge' => $badge,
    'wrapperClass' => 'ff-members-list-insights',
])
    @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $hero, 'compact' => true])

    @include('filament.tenant.partials.ops-overview.kpi-grid', ['items' => $kpiItems])

    @include('filament.tenant.partials.ops-overview.chips', ['chips' => $chips])

    <div class="overflow-hidden rounded-lg border border-gray-200/90 dark:border-white/10">
        <div class="flex items-center justify-between gap-2 border-b border-gray-200/80 px-3 py-2 dark:border-white/10">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-queue-list class="h-4 w-4 text-amber-500" />
                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                    {{ __('Needs attention') }}
                </h4>
            </div>
            @if (($d['zero_cash_members'] ?? 0) > 0)
                <span class="text-[10px] text-rose-600 dark:text-rose-400">
                    {{ __(':count zero cash', ['count' => $d['zero_cash_members']]) }}
                </span>
            @endif
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($d['attention_queue'] as $member)
                <a href="{{ $member['view_url'] }}"
                    class="flex items-center gap-2 px-3 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-xs font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                        {{ strtoupper(substr($member['name'], 0, 1)) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-900 dark:text-white">
                            <x-arabic-text :text="$member['name']" />
                        </p>
                        <p class="truncate text-[11px] text-gray-400">
                            <x-member::amount :value="$member['contribution_amount']" :currency="$currency"
                                :precision="0" />
                        </p>
                    </div>
                    <span @class([
                        'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold',
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $member['status_key'] === 'active' && ! ($member['has_arrears'] ?? false),
                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $member['status_key'] === 'active' && ($member['has_arrears'] ?? false),
                        'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200' => $member['status_key'] === 'inactive',
                        'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200' => $member['status_key'] === 'withdrawn',
                    ])>{{ $member['status'] }}</span>
                </a>
            @empty
                <div class="px-3 py-6 text-center">
                    <x-heroicon-o-check-circle class="mx-auto h-7 w-7 text-emerald-400" />
                    <p class="mt-2 text-sm text-gray-500">{{ __('Everyone is in good standing') }}</p>
                </div>
            @endforelse
        </div>
    </div>

    @include('filament.tenant.partials.ops-overview.links', ['links' => $links])

    @if (method_exists($this, 'isSectionUnfolded'))
        <x-ff-lazy-fold section="value_chart_concentration" :unfolded="$this->isSectionUnfolded('value_chart_concentration')"
            :title="__('Value chart · Exposure concentration')" :hint="__('Expand to load top borrower balances and guarantor risk (cached).')">
            @if ($this->isSectionUnfolded('value_chart_concentration'))
                @include('filament.partials.insights.value-chart', [
                    'chart' => app(\App\Services\ValueChartsService::class)->concentrationExposure(),
                ])
            @endif
        </x-ff-lazy-fold>
    @endif
@endcomponent
