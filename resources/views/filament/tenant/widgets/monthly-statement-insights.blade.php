@php
    $d = $this->getData();
    $pipeline = $d['pipeline'];
    $delivery = $d['delivery'];
    $latest = $d['latest_period'];
    $maxPeriod = max(1, collect($d['period_breakdown'])->max('count'));
    $sparkMax = max(1, max($d['sparkline']));
    $currency = $delivery['currency'];
    $accentBar = ['amber' => 'bg-amber-500', 'emerald' => 'bg-emerald-500', 'rose' => 'bg-rose-500', 'sky' => 'bg-sky-500', 'violet' => 'bg-violet-500', 'teal' => 'bg-teal-500'];
    $accentIcon = ['amber' => 'text-amber-500', 'emerald' => 'text-emerald-500', 'rose' => 'text-rose-500', 'sky' => 'text-sky-500', 'violet' => 'text-violet-500', 'teal' => 'text-teal-500'];
    $kpis = [
        ['key' => 'pending', 'label' => __('Unsent'), 'value' => $d['pending_notify'], 'sub' => __('Notify'), 'icon' => 'heroicon-o-envelope', 'accent' => 'amber', 'active' => $d['pending_notify'] > 0],
        ['key' => 'notified', 'label' => __('Sent'), 'value' => $d['notified'], 'sub' => __(':percent%', ['percent' => $d['notify_rate']]), 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true],
        ['key' => 'total', 'label' => __('Statements'), 'value' => $d['total'], 'sub' => __('All time'), 'icon' => 'heroicon-o-document-chart-bar', 'accent' => 'sky', 'active' => true],
        ['key' => 'new', 'label' => __('Gen/mo'), 'value' => $d['generated_this_month'], 'sub' => $d['mom_change'] !== null ? __(':percent%', ['percent' => $d['mom_change']]) : now()->format('M'), 'icon' => 'heroicon-o-sparkles', 'accent' => 'violet', 'active' => true, 'mom' => $d['mom_change']],
        ['key' => 'coverage', 'label' => __('Coverage'), 'value' => $latest['coverage_rate'] . '%', 'sub' => $latest['label'], 'icon' => 'heroicon-o-chart-pie', 'accent' => 'teal', 'active' => $latest['coverage_rate'] < 100],
        ['key' => 'missing', 'label' => __('Missing'), 'value' => $latest['missing'], 'sub' => __('Members'), 'icon' => 'heroicon-o-user-minus', 'accent' => 'rose', 'active' => $latest['missing'] > 0],
    ];
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1">
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
        <div @class([
            'ff-app-insights-hero overflow-hidden rounded-xl border px-3 py-2.5 shadow-sm lg:col-span-1',
            'border-amber-200/80 bg-gradient-to-r from-amber-50 to-emerald-50/80 dark:border-amber-500/30 dark:from-amber-950/40 dark:to-emerald-950/20' => $d['needs_attention'] > 0,
            'border-emerald-200/70 bg-gradient-to-r from-emerald-50 to-teal-50/60 dark:border-emerald-500/25 dark:from-emerald-950/30 dark:to-teal-950/20' => $d['needs_attention'] === 0,
        ])>
            <div class="flex items-center justify-between gap-2">
                @if ($d['needs_attention'] > 0)
                    <div class="flex min-w-0 items-center gap-2">
                        <x-heroicon-o-document-chart-bar class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                        <div class="min-w-0">
                            <p class="truncate text-xs font-semibold text-amber-900 dark:text-amber-100">
                                {{ __('Statements need your attention') }}</p>
                            <p class="truncate text-[11px] text-gray-600 dark:text-gray-400">
                                {{ trans_choice(':count unsent|:count unsent', $d['pending_notify'], ['count' => $d['pending_notify']]) }}
                                @if ($latest['missing'] > 0)
                                    · {{ trans_choice(':count missing|:count missing', $latest['missing'], ['count' => $latest['missing']]) }}
                                @endif
                                · {{ number_format($latest['contrib_sum'], 0) }} {{ $currency }}
                                @if ($d['pending_notify'] > 0)
                                    · <span
                                        class="text-red-600 dark:text-red-400">{{ trans_choice(':count unsent|:count unsent', $d['pending_notify'], ['count' => $d['pending_notify']]) }}</span>
                                @endif
                            </p>
                        </div>
                    </div>
                    <a href="{{ $pipeline['statements_url'] }}"
                        class="shrink-0 rounded-lg bg-amber-600 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-amber-500 dark:bg-amber-500">
                        {{ __('Review') }}
                    </a>
                @else
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-check-badge class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                        <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ __('Delivery on track') }}</p>
                    </div>
                @endif
            </div>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 lg:col-span-2">
            <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700 sm:grid-cols-6">
                @foreach ($kpis as $i => $card)
                    @php
                        $barClass = $accentBar[$card['accent']] ?? 'bg-gray-400';
                        $iconClass = $accentIcon[$card['accent']] ?? 'text-gray-400';
                        $barOpacity = $card['active'] ? 'opacity-100' : 'opacity-25';
                    @endphp
                    <div class="ff-app-insights-kpi relative px-2.5 py-2 transition hover:bg-gray-50/80 dark:hover:bg-gray-800/60"
                        style="animation: ff-stat-in 0.35s ease-out {{ 0.02 + ($i * 0.03) }}s both">
                        <div @class(['absolute inset-y-0 left-0 w-0.5', $barClass, $barOpacity])></div>
                        <div class="flex items-center justify-between gap-1 pl-1">
                            <x-dynamic-component :component="$card['icon']" @class(['h-3.5 w-3.5', $iconClass]) />
                            @if ($card['key'] === 'new' && isset($card['mom']) && $card['mom'] !== null)
                                <span @class([
                                    'text-[9px] font-bold',
                                    'text-emerald-600 dark:text-emerald-400' => $card['mom'] >= 0,
                                    'text-rose-600 dark:text-rose-400' => $card['mom'] < 0,
                                ])>{{ $card['mom'] >= 0 ? '↑' : '↓' }}{{ abs($card['mom']) }}%</span>
                            @endif
                        </div>
                        <p class="mt-0.5 pl-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">
                            {{ ui_label($card['label']) }}</p>
                        <p class="pl-1 text-lg font-bold tabular-nums leading-tight text-gray-900 dark:text-white">
                            {{ $card['value'] }}@if (!empty($card['suffix'] ?? null))<span
                            class="text-[10px] font-normal text-gray-400">{{ $card['suffix'] }}</span>@endif
                        </p>
                        <p class="pl-1 text-[10px] text-gray-400">{{ ui_label($card['sub']) }}</p>
                    </div>
                @endforeach
            </div>
            @if (collect($d['sparkline'])->sum() > 0)
                <div class="flex h-5 items-end gap-px border-t border-gray-100 px-2 py-1 dark:border-gray-700">
                    @foreach ($d['sparkline'] as $point)
                        @php $h = max(20, (int) round(($point / $sparkMax) * 100)); @endphp
                        <div class="flex-1 rounded-sm bg-indigo-400/70 dark:bg-indigo-500/60" style="height: {{ $h }}%"></div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-funnel class="h-4 w-4 text-emerald-500" />
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Pipeline') }}
                    </h3>
                </div>
                @if ($latest['contrib_sum'] > 0)
                    <span class="text-[10px] font-medium text-emerald-700 dark:text-emerald-300">
                        {{ number_format($latest['contrib_sum'], 0) }} {{ $currency }} {{ __('this mo') }}
                    </span>
                @endif
            </div>
            <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700">
                <a href="{{ $pipeline['statements_url'] }}"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-amber-50/70 dark:hover:bg-amber-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['pending_notify'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Unsent') }}</span>
                </a>
                <a href="{{ $pipeline['statements_url'] }}"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-emerald-50/70 dark:hover:bg-emerald-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['total_statements'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Total') }}</span>
                </a>
                <a href="{{ $pipeline['members_url'] }}"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['missing_latest'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Missing') }}</span>
                </a>
            </div>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 md:col-span-1">
            <div
                class="grid grid-cols-1 divide-y divide-gray-100 dark:divide-gray-700 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                <div class="px-3 py-2.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('Recent periods') }}</p>
                    <div class="mt-2 space-y-1.5">
                        @foreach ($d['period_breakdown'] as $tier)
                            @php $width = $maxPeriod > 0 ? round(($tier['count'] / $maxPeriod) * 100) : 0; @endphp
                            <div>
                                <div class="mb-0.5 flex justify-between text-[10px]">
                                    <span class="text-gray-600 dark:text-gray-300">{{ $tier['label'] }}</span>
                                    <span class="tabular-nums text-gray-400">{{ $tier['count'] }}</span>
                                </div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                    <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500"
                                        style="width: {{ max($tier['count'] > 0 ? 6 : 0, $width) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="px-3 py-2.5">
                    <div class="flex items-center gap-1">
                        <x-heroicon-o-arrow-path-rounded-square class="h-3.5 w-3.5 text-emerald-500" />
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ __('Latest period') }}</p>
                    </div>
                    <p class="mt-1.5 text-lg font-bold tabular-nums text-gray-900 dark:text-white">
                        {{ number_format($latest['contrib_sum'], 0) }} <span
                            class="text-[10px] font-normal text-gray-400">{{ $currency }}</span>
                    </p>
                    <p class="text-[10px] text-gray-400">
                        {{ trans_choice(':count generated|:count generated', $latest['count'], ['count' => $latest['count']]) }}
                        · {{ number_format($latest['repay_sum'], 0) }} {{ __('repaid') }}
                    </p>
                    <div class="mt-2">
                        <div class="mb-0.5 flex justify-between text-[10px] text-gray-500">
                            <span>{{ __('Notify rate') }}</span>
                            <span class="font-semibold text-emerald-600">{{ $delivery['notify_rate'] }}%</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-emerald-500" style="width: {{ $delivery['notify_rate'] }}%">
                            </div>
                        </div>
                    </div>
                    <div class="mt-2">
                        <div class="mb-0.5 flex justify-between text-[10px] text-gray-500">
                            <span>{{ __('Period coverage') }}</span>
                            <span class="font-semibold text-sky-600">{{ $latest['coverage_rate'] }}%</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-sky-500"
                                style="width: {{ min(100, $latest['coverage_rate']) }}%">
                            </div>
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
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-queue-list class="h-4 w-4 text-amber-500" />
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('Unsent queue') }}</h4>
                </div>
                @if ($d['pending_notify'] > 0)
                    <span
                        class="rounded bg-red-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ __('SLA') }}</span>
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
                                {{ $item['closing_display'] }}</p>
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
