@php
    $d = $this->getData();
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1">
    @if (empty($d))
        <div
            class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            {{ __('Loading transactions insights…') }}
        </div>
    @else
        @include('filament.tenant.widgets.partials.insights-head', [
            'hero' => $d['hero'],
            'kpis' => $d['kpis'],
            'sparkline' => $d['sparkline'],
            'sparklineMax' => $d['sparkline_max'],
        ])

        <div class="grid grid-cols-1 gap-3 xl:grid-cols-3">
            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 xl:col-span-2">
                <div
                    class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-chart-bar class="h-4 w-4 text-indigo-500" />
                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ __('30-day flow trend') }}</h3>
                    </div>
                    <div class="text-[10px] text-gray-400">
                        {{ __('Credits') }} {{ \App\Support\Insights\InsightFormatter::money($d['summary']['credits_total']) }}
                        ·
                        {{ __('Debits') }} {{ \App\Support\Insights\InsightFormatter::money($d['summary']['debits_total']) }}
                    </div>
                </div>
                <div class="px-3 py-3">
                    <div class="flex h-32 items-end gap-1">
                        @foreach ($d['trend'] as $day)
                            @php
                                $barTone = match ($day['tone']) {
                                    'emerald' => 'from-emerald-500 to-teal-500',
                                    'rose' => 'from-rose-500 to-amber-500',
                                    default => 'from-gray-300 to-gray-400 dark:from-gray-600 dark:to-gray-500',
                                };
                            @endphp
                            <div class="flex min-w-0 flex-1 flex-col items-center gap-1" title="{{ $day['label'] }} · {{ __('Flow') }} {{ \App\Support\Insights\InsightFormatter::money($day['flow_total']) }} · {{ __('Net flow') }} {{ \App\Support\Insights\InsightFormatter::money($day['net_total']) }}">
                                <div class="flex h-24 w-full items-end rounded-md bg-gray-50 px-[1px] dark:bg-gray-900/30">
                                    <div class="w-full rounded-t-md bg-gradient-to-t {{ $barTone }}"
                                        style="height: {{ min(100, (float) $day['flow_bar']) }}%"></div>
                                </div>
                                @if ($loop->first || $loop->last || $loop->iteration % 6 === 0)
                                    <span class="text-[8px] text-gray-400">{{ str($day['label'])->after(' ') }}</span>
                                @else
                                    <span class="text-[8px] text-transparent">.</span>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-3">
                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900/30">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                                {{ __('Net flow') }}</p>
                            <p @class([
                                'mt-1 text-sm font-bold tabular-nums',
                                $d['summary']['net_total'] >= 0
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-rose-600 dark:text-rose-400',
                            ])>{{ \App\Support\Insights\InsightFormatter::money($d['summary']['net_total']) }}</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900/30">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                                {{ __('Linked source') }}</p>
                            <p class="mt-1 text-sm font-bold tabular-nums text-gray-900 dark:text-white">
                                {{ $d['summary']['linked_source_percent'] === null ? '—' : number_format($d['summary']['linked_source_percent'], 1) . '%' }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900/30">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                                {{ __('Transaction count') }}</p>
                            <p class="mt-1 text-sm font-bold tabular-nums text-gray-900 dark:text-white">
                                {{ number_format($d['summary']['transaction_count']) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div
                    class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-funnel class="h-4 w-4 text-sky-500" />
                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ __('Current view') }}</h3>
                    </div>
                </div>
                <div class="space-y-2 px-3 py-2.5">
                    @foreach ([
                        __('Scope') => $d['filters']['scope'],
                        __('Direction') => $d['filters']['direction'],
                        __('Account type') => $d['filters']['account_type'],
                        __('Transaction type') => $d['filters']['transaction_type'],
                        __('Linked source') => $d['filters']['linked_source'],
                        __('Search') => $d['filters']['search'],
                    ] as $label => $value)
                        <div class="flex items-center justify-between gap-2 text-xs">
                            <span class="text-gray-500">{{ $label }}</span>
                            <span class="truncate text-right font-semibold text-gray-900 dark:text-white">{{ $value }}</span>
                        </div>
                    @endforeach

                    <div class="pt-2">
                        <div class="mb-1 flex items-center justify-between text-[10px] text-gray-500">
                            <span>{{ __('Linked-source coverage') }}</span>
                            <span class="font-semibold text-sky-600 dark:text-sky-400">
                                {{ $d['summary']['linked_source_percent'] === null ? '—' : number_format($d['summary']['linked_source_percent'], 1) . '%' }}
                            </span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-sky-500"
                                style="width: {{ min(100, (float) ($d['summary']['linked_source_percent'] ?? 0)) }}%">
                            </div>
                        </div>
                        <p class="mt-1 text-[10px] text-gray-400">
                            {{ __(':linked linked · :manual manual / unlinked', [
                                'linked' => number_format($d['summary']['linked_source_count']),
                                'manual' => number_format($d['summary']['manual_or_unlinked_count']),
                            ]) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 xl:grid-cols-3">
            @foreach ([
                'scope' => __('Scope mix'),
                'account_type' => __('Account-type mix'),
                'business_type' => __('Transaction-type mix'),
            ] as $key => $title)
                <div
                    class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div
                        class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ $title }}</h3>
                    </div>
                    <div class="space-y-2 px-3 py-2.5">
                        @forelse ($d['breakdowns'][$key] as $row)
                            @php
                                $barTone = match ($row['tone']) {
                                    'emerald' => 'bg-emerald-500',
                                    'rose' => 'bg-rose-500',
                                    default => 'bg-gray-400',
                                };
                            @endphp
                            <div>
                                <div class="mb-1 flex items-start justify-between gap-2 text-xs">
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-gray-900 dark:text-white">{{ $row['label'] }}</p>
                                        <p class="text-[10px] text-gray-400">{{ $row['count_display'] }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $row['share_percent'] }}%</p>
                                        <p class="text-[10px] text-gray-400">{{ __('Net') }} {{ $row['net_display'] }}</p>
                                    </div>
                                </div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                    <div class="h-full rounded-full {{ $barTone }}"
                                        style="width: {{ min(100, (float) $row['bar_width']) }}%"></div>
                                </div>
                            </div>
                        @empty
                            <div class="px-1 py-4 text-center text-[11px] text-gray-400">
                                {{ __('No activity in current view') }}
                            </div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
