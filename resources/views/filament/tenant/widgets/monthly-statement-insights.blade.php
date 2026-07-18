@php
    $d = $this->getData();
    $pipeline = $d['pipeline'];
    $delivery = $d['delivery'];
    $latest = $d['latest_period'];
    $filters = $d['filter_urls'];
    $maxPeriod = max(1, collect($d['period_breakdown'])->max('count'));
    $currency = $delivery['currency'];
    $kpis = [
        ['key' => 'pending', 'label' => __('Unsent'), 'value' => $d['pending_notify'], 'sub' => __('Notify'), 'icon' => 'heroicon-o-envelope', 'accent' => 'amber', 'active' => $d['pending_notify'] > 0, 'url' => $filters['unsent']],
        ['key' => 'notified', 'label' => __('Sent'), 'value' => $d['notified'], 'sub' => __(':percent%', ['percent' => $d['notify_rate']]), 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true, 'url' => $filters['sent']],
        ['key' => 'total', 'label' => __('Statements'), 'value' => $d['total'], 'sub' => __('All time'), 'icon' => 'heroicon-o-document-chart-bar', 'accent' => 'sky', 'active' => true, 'url' => $filters['all']],
        ['key' => 'new', 'label' => __('Gen/mo'), 'value' => $d['generated_this_month'], 'sub' => $d['mom_change'] !== null ? __(':percent%', ['percent' => $d['mom_change']]) : now()->format('M'), 'icon' => 'heroicon-o-sparkles', 'accent' => 'violet', 'active' => true, 'mom' => $d['mom_change']],
        ['key' => 'coverage', 'label' => __('Coverage'), 'value' => $latest['coverage_rate'] . '%', 'sub' => $latest['missing'] > 0 ? trans_choice(':count missing', $latest['missing'], ['count' => $latest['missing']]) : $latest['label'], 'icon' => 'heroicon-o-chart-pie', 'accent' => $latest['missing'] > 0 ? 'rose' : 'teal', 'active' => $latest['coverage_rate'] < 100, 'url' => $latest['missing'] > 0 ? $pipeline['members_url'] : $filters['latest_period']],
    ];
@endphp
    
    @php
        $stmtSub = trans_choice(':count unsent', $d['pending_notify'], ['count' => $d['pending_notify']]);
        if ($latest['missing'] > 0) {
            $stmtSub .= ' · ' . trans_choice(':count missing', $latest['missing'], ['count' => $latest['missing']]);
        }
        $hero = $d['needs_attention'] > 0
            ? ['title' => __('Statements need your attention'), 'subtitle' => $stmtSub, 'tone' => 'amber', 'cta_url' => $filters['unsent'], 'cta_label' => __('Review')]
            : ['title' => __('Delivery on track'), 'subtitle' => __('All statements sent · period covered'), 'tone' => 'success'];
    @endphp
    
    <div class="ff-app-insights w-full max-w-none space-y-3 mb-1">
        @include('filament.tenant.widgets.partials.insights-head', [
            'hero' => $hero,
            'kpis' => $kpis,
        ])
    
        <div
            clas    s="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div
                class="g    rid grid-cols-1 divide-y d
                    ivide-gray-100 dark:divide-gray-700 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
            <div     class="px-3 py-2.5">
                <p c    lass="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                    {{ __('Recent periods') }}</p>
                <div class="mt-2 space-y-1.5">
                    @foreach ($d['period_breakdown'] as $tier)
                        @php $width = $maxPeriod > 0 ? round(($tier['count'] / $maxPeriod) * 100) : 0; @endphp
                        <a href="{{ $filters['all'] }}?{{ http_build_query(['tableFilters' => ['period' => ['period' => $tier['period']]]]) }}"
                            class="block transition hover:opacity-80">
                            <div class="mb-0.5 flex justify-between text-[10px]">
                                <span class="text-gray-600 dark:text-gray-300">{{ $tier['label'] }}</span>
                                <span class="tabular-nums text-gray-400">{{ $tier['count'] }}</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500"
                                        style="width: {{ max($tier['count'] > 0 ? 6 : 0, $width) }}%"></div>
                                </div>
                            </a>
                    @endforeach
                    </div>
            </div>
                <div class="    px-3 py-2.5">
                <div cla    ss="flex items-center justify-between gap-2">
                    <div cla    ss="flex items-center gap
                            -1">
                            <x-heroicon-o-arrow-path-rounded-square class="h-3.5 w-3.5 text-emerald-500" />
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                                {{ __('Latest period') }}</p>
                        </div>
                        <a href="{{ $filters['latest_period'] }}"
                            class="text-[10px] font-medium text-emerald-700 hover:underline dark:text-emerald-300">
                            {{ $latest['label'] }}
                        </a>
                    </div>
                    <p class="mt-1.5 text-lg font-bold tabular-nums text-gray-900 dark:text-white">
                        <x-member::amount :value="$latest['contrib_sum']" :currency="$currency" :precision="0" />
                    </p>
                    <p class="text-[10px] text-gray-400">
                        {{ trans_choice(':count generated|:count generated', $latest['count'], ['count' => $latest['count']]) }}
                        · {{ number_format($latest['repay_sum'], 0) }} {{ __('repaid') }}
                    </p>
                    <div class="mt-2">
                        <div class="mb-0.5 flex justify-between text-[10px] text-gray-500">
                            <span>{{ __('Period notify rate') }}</span>
                            <span class="font-semibold text-emerald-600">{{ $latest['notify_rate'] }}%</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-emerald-500" style="width: {{ $latest['notify_rate'] }}%">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    
        <div class="grid grid-cols-1 items-stretch gap-3 md:grid-cols-2">
            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div
                    class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <div class="flex items-center ga
                        p-1.5">
                        <x-heroicon-o-queue-list class="h-4 w-4 text-amber-500" />
                        <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('Unsent queue') }}</h4>
                </div>
                    @if ($d['pending_notify'] > 0)
                        <a href="{{ $filters['unsent'] }}"
                            class="rounded bg-red-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-red-700 hover:underline dark:bg-red-900/40 dark:text-red-300">{{ __('SLA') }}</a>
                    @endif
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($d['unnotified_queue'] as $item)
                    <a href="{{ $item['queue_url'] }}"
                        class="flex items-center gap-2 px-3 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-[10px] font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                            {{ strtoupper(substr($item['name'], 0, 1)) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-medium text-gray-900 dark:text-white">{{ $item['name'] }}</p>
                            <p class="truncate text-[10px] text-gray-400">{{ $item['period_label'] }} ·
                                <x-ff-money-text :text="$item['closing_display']" /></p>
                        </div>
                        <span @class([
        'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
        'bg-amber-100 text-amber-800 dark:bg-amber-900/40' => $item['days_waiting'] <= 3,
        'bg-red-100 text-red-800 dark:bg-red-900/40' => $item['days_waiting'] > 3,
    ])>
                            {{ $item['days_waiting'] }}d
                        </span>
                    </a>
                @empty
                    <div class="px-3 py-6 text-center">
                        <x-heroicon-o-check-circle class="mx-auto h-6 w-6 text-emerald-400" />
                        <p class="mt-1 text-xs text-gray-500">{{ __('Queue is empty') }}</p>
                    </div>
                @endforelse
            </div>
        </div>

        @include('filament.partials.insights.six-month-workflow-panel', [
    'title' => __('6-month statement delivery'),
    'trend' => $d['trend'],
    'primaryLabel' => __('Notified'),
    'secondaryLabel' => __('Delivered'),
])
    </div>
</div>
