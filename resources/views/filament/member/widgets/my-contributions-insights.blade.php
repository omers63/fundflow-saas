@php
    $d = $this->getData();
@endphp

@if (empty($d))
    <div
        class="rounded-xl border border-dashed border-gray-200 px-4 py-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {{ __('Loading contribution insights…') }}
    </div>
@else
    @php
        $open = $d['open_cycle'];
        $maxTrend = max(1, (float) $d['trend_max']);
        $maxMethod = max(1, collect($d['method_breakdown'])->max('count') ?: 0);
    @endphp

    <div class="ff-app-insights ff-member-contributions-insights w-full max-w-none space-y-2.5 mb-1">
        <div class="grid grid-cols-1 gap-2.5 lg:grid-cols-3">
            @include('filament.member.widgets.partials.insights-hero', ['hero' => $d['hero']])

            <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:col-span-2">
                @include('filament.member.widgets.partials.insights-kpi-strip', [
                    'kpis' => $d['kpis'],
                    'sparkline' => $d['sparkline'],
                    'sparklineMax' => $d['sparkline_max'],
                ])
            </div>
        </div>

        @if ($d['arrears']['visible'] ?? false)
            <div
                class="overflow-hidden rounded-xl border border-rose-300/80 bg-gradient-to-r from-rose-50 via-amber-50/60 to-white px-3 py-2 shadow-sm ring-1 ring-rose-200/70 dark:border-rose-500/35 dark:from-rose-950/50 dark:via-amber-950/30 dark:to-gray-900">
                <div class="flex flex-col items-stretch gap-2">
                    <div class="flex items-start gap-2">
                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-rose-600 dark:text-rose-400" />
                        <div>
                            <p class="text-xs font-semibold text-rose-950 dark:text-rose-50">{{ __('Catch up on missed periods') }}</p>
                            <p class="mt-0.5 text-[10px] text-rose-900/80 dark:text-rose-100/90">
                                {{ implode(' · ', $d['arrears']['periods']) }}
                            </p>
                        </div>
                    </div>
                    <a href="{{ $d['filters']['posted'] }}"
                        class="self-start rounded-lg bg-rose-600 px-4 py-2 text-xs font-semibold text-white hover:bg-rose-500">
                        {{ __('View history') }}
                    </a>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-2.5 md:grid-cols-12">
            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 md:col-span-5">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-1.5 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-arrow-path-rounded-square class="h-3.5 w-3.5 text-emerald-500" />
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('Open cycle') }}</h3>
                    </div>
                    <span @class([
                        'rounded px-1.5 py-0.5 text-[9px] font-bold uppercase',
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $open['status_key'] === 'posted',
                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => in_array($open['status_key'], ['exempt', 'short'], true),
                        'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200' => in_array($open['status_key'], ['ready', 'waiting'], true),
                        'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => $open['status_key'] === 'na',
                    ])>{{ $open['status_label'] }}</span>
                </div>
                <div class="space-y-2 px-3 py-2 text-[11px] text-gray-600 dark:text-gray-300">
                    <p class="text-[10px] text-gray-400">{{ $open['window'] }}</p>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-lg bg-gray-50 px-2 py-1.5 dark:bg-gray-900/50">
                            <p class="text-[9px] uppercase tracking-wide text-gray-400">{{ __('Required cash') }}</p>
                            <p class="font-bold tabular-nums text-gray-900 dark:text-white">{{ $open['required_cash'] }}</p>
                        </div>
                        <div @class([
                            'rounded-lg px-2 py-1.5',
                            'bg-emerald-50 dark:bg-emerald-950/30' => $open['cash_ready'],
                            'bg-amber-50 dark:bg-amber-950/30' => ! $open['cash_ready'],
                        ])>
                            <p class="text-[9px] uppercase tracking-wide text-gray-400">{{ __('Your cash') }}</p>
                            <p @class([
                                'font-bold tabular-nums',
                                'text-emerald-700 dark:text-emerald-300' => $open['cash_ready'],
                                'text-amber-700 dark:text-amber-300' => ! $open['cash_ready'],
                            ])>{{ $open['cash_balance'] }}</p>
                        </div>
                    </div>
                    @if (! $open['cash_ready'] && ($open['cash_shortfall_raw'] ?? 0) > 0)
<div>
                            <div class="mb-0.5 flex justify-between text-[10px]">
                                <span>{{ __('Cash readiness') }}</span>
                                <span class="font-semibold text-amber-600">{{ $open['cash_shortfall'] }} {{ __('short') }}</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div class="h-full rounded-full bg-gradient-to-r from-amber-500 to-emerald-500"
                                    style="width: {{ $open['cash_ready_pct'] }}%"></div>
                            </div>
                        </div>
                    @endif
                    <div class="flex flex-wrap items-center justify-between gap-2 pt-0.5">
                        <span class="text-[10px] text-gray-400">
                            @if ($open['is_late'])
                                <span class="font-semibold text-rose-600 dark:text-rose-400">{{ __('Past due') }}</span>
                            @else
                                {{ trans_choice(':count day left|:count days left', $open['days_until_deadline'], ['count' => $open['days_until_deadline']]) }}
                                · {{ __('Due :date', ['date' => $open['deadline_label']]) }}
                            @endif
                        </span>
                        <div class="flex gap-1.5">
                            @if (! $open['cash_ready'])
                                <a href="{{ $open['deposits_url'] }}"
                                    class="rounded-md bg-amber-600 px-2 py-0.5 text-[10px] font-semibold text-white hover:bg-amber-500">
                                    {{ __('Deposit') }}
                                </a>
                            @endif
                            <a href="{{ $open['cash_account_url'] }}"
                                class="rounded-md border border-gray-200 px-2 py-0.5 text-[10px] font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200">
                                {{ __('Cash') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 md:col-span-4">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-1.5 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-chart-bar class="h-3.5 w-3.5 text-indigo-500" />
                        <h4 class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('6-month payments') }}</h4>
                    </div>
                    <span class="text-[10px] font-medium text-emerald-700 dark:text-emerald-300">{{ $d['summary']['posted_last_12'] }}</span>
                </div>
                <div class="px-2.5 py-2">
                    <div class="flex h-16 items-end gap-1">
                        @foreach ($d['trend'] as $month)
                            @php
                                $barH = max(10, (int) round(($month['posted_amount'] / $maxTrend) * 100));
                            @endphp
                            <div class="flex flex-1 flex-col items-center gap-0.5">
                                <span class="text-[9px] font-semibold tabular-nums text-gray-400">
                                    {{ $month['posted'] > 0 ? $month['posted'] : '·' }}
                                </span>
                                <div class="flex w-full max-w-[2rem] flex-col justify-end overflow-hidden rounded-t-md ring-1 ring-gray-200/50 dark:ring-gray-600"
                                    style="height: {{ $barH }}%">
                                    @if ($month['posted_amount'] > 0)
                                        <div class="w-full bg-emerald-500" style="height: 100%"></div>
                                    @elseif ($month['pending'] > 0 || $month['failed'] > 0)
                                        <div class="h-1 w-full bg-amber-400"></div>
                                    @else
                                        <div class="h-0.5 w-full bg-gray-200 dark:bg-gray-600"></div>
                                    @endif
                                </div>
                                <span class="text-[9px] text-gray-400">{{ $month['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 md:col-span-3">
                <div class="border-b border-gray-100 px-3 py-1.5 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-chart-pie class="h-3.5 w-3.5 text-violet-500" />
                        <h4 class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('Your rhythm') }}</h4>
                    </div>
                </div>
                <div class="space-y-2 px-3 py-2">
                    <div class="flex items-center gap-2">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-violet-500 to-indigo-600 text-sm font-bold text-white tabular-nums">
                            {{ $d['consistency']['display'] }}
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ __('On-time rate') }}</p>
                            <p class="text-[10px] text-gray-400">
                                {{ __(':posted of :liable cycles', ['posted' => $d['consistency']['posted'], 'liable' => $d['consistency']['liable']]) }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center justify-between rounded-lg bg-violet-50 px-2 py-1.5 dark:bg-violet-950/30">
                        <span class="text-[10px] text-violet-800 dark:text-violet-200">{{ __('Posted streak') }}</span>
                        <span class="text-sm font-bold tabular-nums text-violet-700 dark:text-violet-300">
                            {{ trans_choice(':count mo|:count mos', $d['streak'], ['count' => $d['streak']]) }}
                        </span>
                    </div>
                    @if (count($d['method_breakdown']) > 0)
                        <div class="space-y-1">
                            <p class="text-[9px] font-semibold uppercase tracking-wide text-gray-400">{{ __('How you pay') }}</p>
                            @foreach ($d['method_breakdown'] as $tier)
                                @php $width = $maxMethod > 0 ? round(($tier['count'] / $maxMethod) * 100) : 0; @endphp
                                <div>
                                    <div class="mb-0.5 flex justify-between text-[9px]">
                                        <span class="truncate text-gray-600 dark:text-gray-300">{{ $tier['label'] }}</span>
                                        <span class="tabular-nums text-gray-400">{{ $tier['count'] }}</span>
                                    </div>
                                    <div class="h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                        <div class="h-full rounded-full bg-violet-500"
                                            style="width: {{ max($tier['count'] > 0 ? 8 : 0, $width) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <p class="text-[9px] text-gray-400">
                        {{ __('Monthly target') }}:
                        <span class="font-semibold text-gray-600 dark:text-gray-300">{{ $d['monthly'] }}</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
@endif
