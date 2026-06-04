@php
    $d = $this->getData();
    $pipeline = $d['pipeline'];
    $docs = $d['docs'];
    $bank = $d['bank'];
    $maxAmountTier = max(1, collect($d['amount_breakdown'])->max('count'));
    $sparkMax = max(1, max($d['sparkline']));
    $currency = $docs['currency'];

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

    $kpis = \App\Support\Insights\InsightKpi::linkMany([
        ['key' => 'pending', 'label' => __('Pending'), 'value' => $d['pending'], 'sub' => __('Awaiting'), 'icon' => 'heroicon-o-clock', 'accent' => 'amber', 'active' => $d['pending'] > 0],
        ['key' => 'accepted', 'label' => __('Accepted'), 'value' => $d['accepted'], 'sub' => __(':count/mo', ['count' => $d['accepted_this_month']]), 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true],
        ['key' => 'rejected', 'label' => __('Rejected'), 'value' => $d['rejected'], 'sub' => __(':count/mo', ['count' => $d['rejected_this_month']]), 'icon' => 'heroicon-o-x-circle', 'accent' => 'rose', 'active' => $d['rejected'] > 0],
        ['key' => 'new', 'label' => __('New/mo'), 'value' => $d['new_this_month'], 'sub' => $d['mom_change'] !== null ? __(':percent%', ['percent' => $d['mom_change']]) : now()->format('M'), 'icon' => 'heroicon-o-sparkles', 'accent' => 'sky', 'active' => true, 'mom' => $d['mom_change']],
        ['key' => 'rate', 'label' => __('Acceptance'), 'value' => $d['acceptance_rate'] !== null ? $d['acceptance_rate'] . '%' : '—', 'sub' => __('Decided'), 'icon' => 'heroicon-o-chart-pie', 'accent' => 'violet', 'active' => $d['acceptance_rate'] !== null],
        ['key' => 'review', 'label' => __('Avg review'), 'value' => $d['avg_review_days'], 'sub' => __('days'), 'icon' => 'heroicon-o-arrow-path', 'accent' => 'teal', 'active' => true, 'suffix' => __('d')],
    ], [
        'pending' => $pipeline['deposits_pending_url'],
        'accepted' => $pipeline['deposits_accepted_url'],
        'rejected' => $pipeline['deposits_rejected_url'],
        'new' => $pipeline['deposits_url'],
        'rate' => $pipeline['deposits_url'],
        'review' => $pipeline['deposits_url'],
    ]);
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1">
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
        <div @class([
            'ff-app-insights-hero overflow-hidden rounded-xl border px-3 py-2.5 shadow-sm lg:col-span-1',
            'border-amber-200/80 bg-gradient-to-r from-amber-50 to-emerald-50/80 dark:border-amber-500/30 dark:from-amber-950/40 dark:to-emerald-950/20' => $d['pending'] > 0,
            'border-emerald-200/70 bg-gradient-to-r from-emerald-50 to-teal-50/60 dark:border-emerald-500/25 dark:from-emerald-950/30 dark:to-teal-950/20' => $d['pending'] === 0,
        ])>
            <div class="flex items-center justify-between gap-2">
                @if ($d['pending'] > 0)
                    <div class="flex min-w-0 items-center gap-2">
                        <x-heroicon-o-banknotes class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                        <div class="min-w-0">
                            <p class="truncate text-xs font-semibold text-amber-900 dark:text-amber-100">
                                {{ __('Deposits need your attention') }}</p>
                            <p class="truncate text-[11px] text-gray-600 dark:text-gray-400">
                                {{ trans_choice(':count pending|:count pending', $d['pending'], ['count' => $d['pending']]) }}
                                · {{ number_format($d['pending_amount_total'], 0) }} {{ $currency }}
                                @if ($d['pending_over_sla'] > 0)
                                    · <span
                                        class="text-red-600 dark:text-red-400">{{ trans_choice(':count SLA|:count SLA', $d['pending_over_sla'], ['count' => $d['pending_over_sla']]) }}</span>
                                @endif
                            </p>
                        </div>
                    </div>
                    <a href="{{ $pipeline['deposits_pending_url'] }}"
                        class="shrink-0 rounded-lg bg-amber-600 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-amber-500 dark:bg-amber-500">
                        {{ __('Review') }}
                    </a>
                @else
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-check-badge class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                        <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ __('Queue clear') }}</p>
                    </div>
                @endif
            </div>
        </div>

        @include('filament.tenant.widgets.partials.insights-kpi-strip', [
            'kpis' => $kpis,
            'sparkline' => $d['pending'] > 0 ? $d['sparkline'] : null,
            'sparklineMax' => $sparkMax,
        ])
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
                @if ($d['accepted_amount_this_month'] > 0)
                    <span class="text-[10px] font-medium text-emerald-700 dark:text-emerald-300">
                        {{ number_format($d['accepted_amount_this_month'], 0) }} {{ $currency }} {{ __('this mo') }}
                    </span>
                @endif
            </div>
            <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700">
                <a href="{{ $pipeline['deposits_pending_url'] }}"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-amber-50/70 dark:hover:bg-amber-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['pending_deposits'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Pending') }}</span>
                </a>
                <a href="{{ $pipeline['deposits_accepted_url'] }}"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-emerald-50/70 dark:hover:bg-emerald-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['accepted_deposits'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Accepted') }}</span>
                </a>
                <a href="{{ $pipeline['bank_url'] }}"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['uncleared_bank'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Uncleared') }}</span>
                </a>
            </div>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 md:col-span-1">
            <div
                class="grid grid-cols-1 divide-y divide-gray-100 dark:divide-gray-700 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                <div class="px-3 py-2.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('Pending by size') }}</p>
                    <div class="mt-2 space-y-1.5">
                        @foreach ($d['amount_breakdown'] as $tier)
                            @php $width = $maxAmountTier > 0 ? round(($tier['count'] / $maxAmountTier) * 100) : 0; @endphp
                            <div>
                                <div class="mb-0.5 flex justify-between text-[10px]">
                                    <span class="text-gray-600 dark:text-gray-300">{{ $tier['label'] }}</span>
                                    <span class="tabular-nums text-gray-400">{{ $tier['count'] }}</span>
                                </div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                    <div class="h-full rounded-full bg-gradient-to-r from-amber-500 to-emerald-500"
                                        style="width: {{ max($tier['count'] > 0 ? 6 : 0, $width) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="px-3 py-2.5">
                    <div class="flex items-center gap-1">
                        <x-heroicon-o-document-check class="h-3.5 w-3.5 text-emerald-500" />
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ __('Receipts & bank') }}</p>
                    </div>
                    <p class="mt-1.5 text-lg font-bold tabular-nums text-gray-900 dark:text-white">
                        {{ number_format($docs['pending_total'], 0) }} <span
                            class="text-[10px] font-normal text-gray-400">{{ $currency }}</span>
                    </p>
                    <p class="text-[10px] text-gray-400">
                        {{ trans_choice(':count w/ receipt|:count w/ receipt', $docs['pending_with_receipt'], ['count' => $docs['pending_with_receipt']]) }}
                    </p>
                    <div class="mt-2">
                        <div class="mb-0.5 flex justify-between text-[10px] text-gray-500">
                            <span>{{ __('Receipt rate') }}</span>
                            <span class="font-semibold text-emerald-600">{{ $docs['receipt_rate'] }}%</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-emerald-500" style="width: {{ $docs['receipt_rate'] }}%">
                            </div>
                        </div>
                    </div>
                    <div class="mt-2">
                        <div class="mb-0.5 flex justify-between text-[10px] text-gray-500">
                            <span>{{ __('Bank cleared') }}</span>
                            <span class="font-semibold text-sky-600">{{ $bank['clearance_rate'] }}%</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-sky-500" style="width: {{ $bank['clearance_rate'] }}%">
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
                        {{ __('Review queue') }}</h4>
                </div>
                @if ($d['pending_over_sla'] > 0)
                    <span
                        class="rounded bg-red-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ __('SLA') }}</span>
                @endif
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($d['oldest_pending'] as $posting)
                    <a href="{{ $posting['queue_url'] }}"
                        class="flex items-center gap-2 px-3 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-[10px] font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                            {{ strtoupper(substr($posting['name'], 0, 1)) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-medium text-gray-900 dark:text-white">{{ $posting['name'] }}</p>
                            <p class="truncate text-[10px] text-gray-400">{{ $posting['amount_display'] }} ·
                                {{ $posting['has_receipt'] ? __('Receipt') : __('No receipt') }}</p>
                        </div>
                        <span @class([
                            'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                            'bg-amber-100 text-amber-800 dark:bg-amber-900/40' => $posting['days_waiting'] <= 3,
                            'bg-red-100 text-red-800 dark:bg-red-900/40' => $posting['days_waiting'] > 3,
                        ])>
                            {{ $posting['days_waiting'] }}d
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
            'title' => __('6-month volume & outcomes'),
            'trend' => $d['trend'],
            'primaryLabel' => __('Accepted'),
            'secondaryLabel' => __('Decided'),
        ])
    </div>
</div>
