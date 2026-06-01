@php
    $d = $this->getData();
    $pollingInterval = $this->getPollingInterval();
    $pipeline = $d['pipeline'];
    $fees = $d['fees'];
    $maxTrend = max(1, collect($d['trend'])->max('total'));
    $maxType = max(1, collect($d['type_breakdown'])->max('count'));
    $sparkMax = max(1, max($d['sparkline']));
    $currency = $fees['currency'];

    $accentBar = [
        'amber' => 'bg-amber-500',
        'emerald' => 'bg-emerald-500',
        'rose' => 'bg-rose-500',
        'sky' => 'bg-sky-500',
        'violet' => 'bg-violet-500',
        'teal' => 'bg-teal-500',
    ];
    $accentIcon = [
        'amber' => 'text-amber-500',
        'emerald' => 'text-emerald-500',
        'rose' => 'text-rose-500',
        'sky' => 'text-sky-500',
        'violet' => 'text-violet-500',
        'teal' => 'text-teal-500',
    ];

    $kpis = [
        ['key' => 'pending', 'label' => __('Pending'), 'value' => $d['pending'], 'sub' => __('Awaiting'), 'icon' => 'heroicon-o-clock', 'accent' => 'amber', 'active' => $d['pending'] > 0],
        ['key' => 'approved', 'label' => __('Approved'), 'value' => $d['approved'], 'sub' => __(':count/mo', ['count' => $d['approved_this_month']]), 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true],
        ['key' => 'rejected', 'label' => __('Rejected'), 'value' => $d['rejected'], 'sub' => __(':count/mo', ['count' => $d['rejected_this_month']]), 'icon' => 'heroicon-o-x-circle', 'accent' => 'rose', 'active' => $d['rejected'] > 0],
        ['key' => 'new', 'label' => __('New/mo'), 'value' => $d['new_this_month'], 'sub' => $d['mom_change'] !== null ? __(':percent%', ['percent' => $d['mom_change']]) : now()->format('M'), 'icon' => 'heroicon-o-sparkles', 'accent' => 'sky', 'active' => true, 'mom' => $d['mom_change']],
        ['key' => 'rate', 'label' => __('Approval'), 'value' => $d['approval_rate'] !== null ? $d['approval_rate'] . '%' : '—', 'sub' => __('Decided'), 'icon' => 'heroicon-o-chart-pie', 'accent' => 'violet', 'active' => $d['approval_rate'] !== null],
        ['key' => 'review', 'label' => __('Avg review'), 'value' => $d['avg_review_days'], 'sub' => __('days'), 'icon' => 'heroicon-o-arrow-path', 'accent' => 'teal', 'active' => true, 'suffix' => __('d')],
    ];
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1" @if (filled($pollingInterval)) wire:poll.{{ $pollingInterval }} @endif>
    {{-- Row 1: alert + overview stats (2 cols on md, 3 on xl) --}}
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
        {{-- Status alert (compact) --}}
        <div @class([
            'ff-app-insights-hero overflow-hidden rounded-xl border px-3 py-2.5 shadow-sm lg:col-span-1',
            'border-amber-200/80 bg-gradient-to-r from-amber-50 to-sky-50/80 dark:border-amber-500/30 dark:from-amber-950/40 dark:to-sky-950/20' => $d['pending'] > 0,
            'border-emerald-200/70 bg-gradient-to-r from-emerald-50 to-teal-50/60 dark:border-emerald-500/25 dark:from-emerald-950/30 dark:to-teal-950/20' => $d['pending'] === 0,
        ])>
            <div class="flex items-center justify-between gap-2">
                @if ($d['pending'] > 0)
                    <div class="flex min-w-0 items-center gap-2">
                        <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                        <div class="min-w-0">
                            <p class="truncate text-xs font-semibold text-amber-900 dark:text-amber-100">
                                {{ __('Applications need your attention') }}</p>
                            <p class="truncate text-[11px] text-gray-600 dark:text-gray-400">
                                {{ trans_choice(':count pending|:count pending', $d['pending'], ['count' => $d['pending']]) }}
                                @if ($d['pending_over_sla'] > 0)
                                    · <span
                                        class="text-red-600 dark:text-red-400">{{ trans_choice(':count SLA|:count SLA', $d['pending_over_sla'], ['count' => $d['pending_over_sla']]) }}</span>
                                @endif
                            </p>
                        </div>
                    </div>
                    <a href="{{ $pipeline['applications_pending_url'] }}"
                        class="shrink-0 rounded-lg bg-amber-600 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-amber-500 dark:bg-amber-500">
                        {{ __('Review') }}
                    </a>
                @else
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-check-badge class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                        <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ __('Inbox clear') }}</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Combined KPI grid (spans 2 cols) --}}
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
            @if ($d['pending'] > 0)
                <div class="flex h-5 items-end gap-px border-t border-gray-100 px-2 py-1 dark:border-gray-700">
                    @foreach ($d['sparkline'] as $point)
                        @php $h = max(20, (int) round(($point / $sparkMax) * 100)); @endphp
                        <div class="flex-1 rounded-sm bg-amber-400/70 dark:bg-amber-500/60" style="height: {{ $h }}%"></div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Row 2: pipeline + breakdown (2 cols) --}}
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        {{-- Pipeline (compact) --}}
        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-funnel class="h-4 w-4 text-sky-500" />
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Pipeline') }}
                    </h3>
                </div>
                @if ($d['new_this_month'] > 0)
                    <span class="text-[10px] font-medium text-sky-700 dark:text-sky-300">+{{ $d['new_this_month'] }}
                        {{ __('this mo') }}</span>
                @endif
            </div>
            <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700">
                <a href="{{ $pipeline['applications_pending_url'] }}"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-amber-50/70 dark:hover:bg-amber-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['pending_apps'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Pending') }}</span>
                </a>
                <a href="{{ $pipeline['applications_approved_url'] }}"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-indigo-50/70 dark:hover:bg-indigo-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-indigo-600 dark:text-indigo-400">{{ $pipeline['approved_apps'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Approved') }}</span>
                </a>
                <a href="{{ $pipeline['members_url'] }}"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-emerald-50/70 dark:hover:bg-emerald-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['active_members'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Members') }}</span>
                    @if ($pipeline['members_joined_month'] > 0)
                        <span
                            class="text-[9px] font-medium text-emerald-600">+{{ $pipeline['members_joined_month'] }}</span>
                    @endif
                </a>
            </div>
        </div>

        {{-- Enrollment + fees combined --}}
        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 md:col-span-1">
            <div
                class="grid grid-cols-1 divide-y divide-gray-100 dark:divide-gray-700 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                <div class="px-3 py-2.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('Enrollment mix') }}</p>
                    <div class="mt-2 space-y-1.5">
                        @foreach ($d['type_breakdown'] as $type)
                            @php $width = $maxType > 0 ? round(($type['count'] / $maxType) * 100) : 0; @endphp
                            <div>
                                <div class="mb-0.5 flex justify-between text-[10px]">
                                    <span class="capitalize text-gray-600 dark:text-gray-300">{{ $type['label'] }}</span>
                                    <span class="tabular-nums text-gray-400">{{ $type['count'] }}</span>
                                </div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                    <div class="h-full rounded-full bg-gradient-to-r from-sky-500 to-indigo-500"
                                        style="width: {{ max($type['count'] > 0 ? 6 : 0, $width) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="px-3 py-2.5">
                    <div class="flex items-center gap-1">
                        <x-heroicon-o-banknotes class="h-3.5 w-3.5 text-sky-500" />
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ __('Fees & docs') }}</p>
                    </div>
                    <p class="mt-1.5 text-lg font-bold tabular-nums text-gray-900 dark:text-white">
                        {{ number_format($fees['pending_total'], 0) }} <span
                            class="text-[10px] font-normal text-gray-400">{{ $currency }}</span>
                    </p>
                    <p class="text-[10px] text-gray-400">
                        {{ trans_choice(':count w/ fee|:count w/ fee', $fees['pending_with_fee'], ['count' => $fees['pending_with_fee']]) }}
                    </p>
                    <div class="mt-2">
                        <div class="mb-0.5 flex justify-between text-[10px] text-gray-500">
                            <span>{{ __('Receipts') }}</span>
                            <span class="font-semibold text-sky-600">{{ $fees['receipt_rate'] }}%</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-sky-500" style="width: {{ $fees['receipt_rate'] }}%">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Row 3: review queue + 6-month chart (2 cols) --}}
    <div class="grid grid-cols-1 items-stretch gap-3 md:grid-cols-2">
        {{-- Review queue (compact) --}}
        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-queue-list class="h-4 w-4 text-amber-500" />
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('Review queue') }}</h4>
                </div>
                @if ($d['pending_over_sla'] > 0)
                    <span
                        class="rounded bg-red-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ __('SLA') }}</span>
                @endif
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($d['oldest_pending'] as $app)
                    <a href="{{ $app['edit_url'] }}"
                        class="flex items-center gap-2 px-3 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-[10px] font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                            {{ strtoupper(substr($app['name'], 0, 1)) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-medium text-gray-900 dark:text-white">{{ $app['name'] }}</p>
                            <p class="truncate text-[10px] text-gray-400">{{ $app['type'] }} ·
                                {{ $app['has_receipt'] ? __('Receipt') : __('No receipt') }}</p>
                        </div>
                        <span @class([
                            'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                            'bg-amber-100 text-amber-800 dark:bg-amber-900/40' => $app['days_waiting'] <= 7,
                            'bg-red-100 text-red-800 dark:bg-red-900/40' => $app['days_waiting'] > 7,
                        ])>
                            {{ $app['days_waiting'] }}d
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

        {{-- 6-month volume & outcomes --}}
        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-chart-bar class="h-4 w-4 text-indigo-500" />
                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                    {{ __('6-month volume & outcomes') }}</h4>
            </div>
            <div class="flex flex-wrap gap-3 text-[10px] text-gray-500">
                <span class="flex items-center gap-1"><span
                        class="h-2 w-2 rounded-sm bg-emerald-500"></span>{{ __('Approved') }}</span>
                <span class="flex items-center gap-1"><span
                        class="h-2 w-2 rounded-sm bg-rose-500"></span>{{ __('Rejected') }}</span>
                <span class="flex items-center gap-1"><span
                        class="h-2 w-2 rounded-sm bg-amber-400"></span>{{ __('Pending') }}</span>
                </div>
            </div>
            <div class="px-3 py-3">
                <div class="flex h-20 items-end gap-1.5 sm:gap-2">
                @foreach ($d['trend'] as $month)
                    @php
                        $stackTotal = max(1, $month['total']);
                        $approvedH = round(($month['approved'] / $stackTotal) * 100);
                        $rejectedH = round(($month['rejected'] / $stackTotal) * 100);
                        $pendingH = max(0, 100 - $approvedH - $rejectedH);
                        $barH = max(12, (int) round(($month['total'] / $maxTrend) * 100));
                    @endphp
                    <div class="flex flex-1 flex-col items-center gap-0.5">
                        <span
                            class="text-[10px] font-semibold tabular-nums text-gray-500">{{ $month['total'] ?: '·' }}</span>
                        <div class="flex w-full max-w-[2.25rem] flex-col justify-end overflow-hidden rounded-t-md ring-1 ring-gray-200/60 dark:ring-gray-600"
                            style="height: {{ $barH }}%">
                            @if ($month['approved'] > 0)
                                <div class="w-full bg-emerald-500" style="height: {{ max(3, $approvedH) }}%"></div>
                            @endif
                            @if ($month['rejected'] > 0)
                                <div class="w-full bg-rose-500" style="height: {{ max(3, $rejectedH) }}%"></div>
                            @endif
                            @if ($month['pending'] > 0)
                                <div class="w-full bg-amber-400" style="height: {{ max(3, $pendingH) }}%"></div>
                            @endif
                            @if ($month['total'] === 0)
                                <div class="h-0.5 w-full bg-gray-200 dark:bg-gray-600"></div>
                            @endif
                        </div>
                        <span class="text-[10px] text-gray-400">{{ $month['label'] }}</span>
                    </div>
                @endforeach
                </div>
            </div>
        </div>
    </div>
</div>