@php
    $maxTrend = max(1, collect($d['trend'])->max('total'));
    $maxStatus = max(1, collect($d['status_breakdown'])->max('count'));
    $sparkMax = max(1, max($d['sparkline']));
    $pipeline = $d['pipeline'];
    $currency = $d['currency'];
@endphp

<div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
    @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $d['hero']])
    @include('filament.tenant.widgets.partials.insights-kpi-strip', [
        'kpis' => $d['kpis'],
        'sparkline' => ($pipeline['needs_decision'] + $pipeline['ready_to_disburse']) > 0 ? $d['sparkline'] : null,
        'sparklineMax' => $sparkMax,
    ])
</div>

<div class="grid grid-cols-1 gap-3 md:grid-cols-2">
    <div class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-funnel class="h-4 w-4 text-sky-500" />
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Operations pipeline') }}</h3>
            </div>
            @if ($d['emergency_in_queue'] > 0)
                <span class="rounded bg-rose-100 px-1.5 py-0.5 text-[9px] font-bold text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">{{ __(':count emergency', ['count' => $d['emergency_in_queue']]) }}</span>
            @endif
        </div>
        <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700 sm:grid-cols-5">
            <a href="{{ $pipeline['queue_url'] }}?tab=needs_decision" class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-amber-50/70 dark:hover:bg-amber-950/20">
                <span class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['needs_decision'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Decision') }}</span>
            </a>
            <a href="{{ $pipeline['queue_url'] }}?tab=ready_to_disburse" class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
                <span class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['ready_to_disburse'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Disburse') }}</span>
            </a>
            <a href="{{ $pipeline['queue_url'] }}?tab=awaiting_payout" class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-indigo-50/70 dark:hover:bg-indigo-950/20">
                <span class="text-xl font-bold tabular-nums text-indigo-600 dark:text-indigo-400">{{ $pipeline['awaiting_payout'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Payout') }}</span>
            </a>
            <a href="{{ $pipeline['loans_url'] }}?tableFilters[status][value]=active" class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-emerald-50/70 dark:hover:bg-emerald-950/20">
                <span class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['active'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Active') }}</span>
            </a>
            <a href="{{ $pipeline['loans_url'] }}?tableFilters[status][value]=completed" class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-gray-50/70 dark:hover:bg-gray-900/20">
                <span class="text-xl font-bold tabular-nums text-gray-600 dark:text-gray-300">{{ $pipeline['completed'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Closed') }}</span>
            </a>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="grid grid-cols-1 divide-y divide-gray-100 dark:divide-gray-700 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
            <div class="px-3 py-2.5">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Status mix') }}</p>
                <div class="mt-2 space-y-1.5">
                    @foreach ($d['status_breakdown'] as $row)
                        @php $width = $maxStatus > 0 ? round(($row['count'] / $maxStatus) * 100) : 0; @endphp
                        <div>
                            <div class="mb-0.5 flex justify-between text-[10px]">
                                <span class="text-gray-600 dark:text-gray-300">{{ $row['label'] }}</span>
                                <span class="tabular-nums text-gray-400">{{ $row['count'] }}</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500" style="width: {{ max($row['count'] > 0 ? 6 : 0, $width) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="px-3 py-2.5">
                <div class="flex items-center gap-1">
                    <x-heroicon-o-circle-stack class="h-3.5 w-3.5 text-indigo-500" />
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Fund pool headroom') }}</p>
                </div>
                <div class="mt-2 space-y-1.5">
                    @foreach ($d['fund_utilization'] as $tier)
                        <div>
                            <div class="mb-0.5 flex justify-between text-[10px]">
                                <span class="truncate text-gray-600 dark:text-gray-300">{{ $tier['label'] }}</span>
                                <span class="shrink-0 tabular-nums text-emerald-600">{{ $tier['available_formatted'] }}</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div @class(['h-full rounded-full', 'bg-rose-500' => $tier['used_percent'] >= 85, 'bg-amber-500' => $tier['used_percent'] >= 60 && $tier['used_percent'] < 85, 'bg-emerald-500' => $tier['used_percent'] < 60]) style="width: {{ max(4, $tier['used_percent']) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 gap-3 md:grid-cols-2">
    <div class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-queue-list class="h-4 w-4 text-amber-500" />
                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Oldest pending') }}</h4>
            </div>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @forelse ($d['oldest_pending'] as $loan)
                <a href="{{ $loan['view_url'] }}" class="flex items-center gap-2 px-3 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-[10px] font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                        {{ strtoupper(substr($loan['member'], 0, 1)) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-medium text-gray-900 dark:text-white">{{ $loan['member'] }}</p>
                        <p class="truncate text-[10px] text-gray-400">
                            {{ number_format($loan['amount'], 0) }} {{ $currency }}
                            @if ($loan['is_emergency']) · <span class="text-rose-500">{{ __('Emergency') }}</span> @endif
                        </p>
                    </div>
                    <span @class([
                        'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40' => $loan['days_waiting'] <= 7,
                        'bg-red-100 text-red-800 dark:bg-red-900/40' => $loan['days_waiting'] > 7,
                    ])>{{ $loan['days_waiting'] }}d</span>
                </a>
            @empty
                <div class="px-3 py-6 text-center">
                    <x-heroicon-o-check-circle class="mx-auto h-6 w-6 text-emerald-400" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('No pending applications') }}</p>
                </div>
            @endforelse
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-chart-bar class="h-4 w-4 text-indigo-500" />
                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('6-month loan volume') }}</h4>
            </div>
            <div class="flex flex-wrap gap-3 text-[10px] text-gray-500">
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-emerald-500"></span>{{ __('Active') }}</span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-amber-400"></span>{{ __('Pending') }}</span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-violet-500"></span>{{ __('Closed') }}</span>
            </div>
        </div>
        <div class="px-3 py-3">
            <div class="flex h-20 items-end gap-1.5 sm:gap-2">
                @foreach ($d['trend'] as $month)
                    @php
                        $stackTotal = max(1, $month['total']);
                        $activeH = round(($month['active'] / $stackTotal) * 100);
                        $pendingH = round(($month['pending'] / $stackTotal) * 100);
                        $completedH = max(0, 100 - $activeH - $pendingH);
                        $barH = max(12, (int) round(($month['total'] / $maxTrend) * 100));
                    @endphp
                    <div class="flex flex-1 flex-col items-center gap-0.5">
                        <span class="text-[10px] font-semibold tabular-nums text-gray-500">{{ $month['total'] ?: '·' }}</span>
                        <div class="flex w-full max-w-[2.25rem] flex-col justify-end overflow-hidden rounded-t-md ring-1 ring-gray-200/60 dark:ring-gray-600" style="height: {{ $barH }}%">
                            @if ($month['active'] > 0)<div class="w-full bg-emerald-500" style="height: {{ max(3, $activeH) }}%"></div>@endif
                            @if ($month['pending'] > 0)<div class="w-full bg-amber-400" style="height: {{ max(3, $pendingH) }}%"></div>@endif
                            @if ($month['completed'] > 0)<div class="w-full bg-violet-500" style="height: {{ max(3, $completedH) }}%"></div>@endif
                            @if ($month['total'] === 0)<div class="h-0.5 w-full bg-gray-200 dark:bg-gray-600"></div>@endif
                        </div>
                        <span class="text-[10px] text-gray-400">{{ $month['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
