@php
    use App\Filament\Support\MoneyDisplay;

    $d = $this->getData();
    $pipeline = $d['pipeline'];
    $docs = $d['docs'];
    $bank = $d['bank'];
    $maxAmountTier = max(1, collect($d['amount_breakdown'])->max('count'));
    $sparkMax = max(1, max($d['sparkline']));
    $currency = $docs['currency'];

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

@php
    $hero = $d['pending'] > 0
        ? [
            'title'     => __('Deposits need your attention'),
            'subtitle'  => trans_choice(':count pending', $d['pending'], ['count' => $d['pending']])
                           . ' · ' . (MoneyDisplay::format($d['pending_amount_total'], $currency, precision: 0) ?? '')
                           . ($d['pending_over_sla'] > 0 ? ' · ' . trans_choice(':count past SLA', $d['pending_over_sla'], ['count' => $d['pending_over_sla']]) : ''),
            'tone'      => 'amber',
            'cta_url'   => $pipeline['deposits_pending_url'],
            'cta_label' => __('Review'),
        ]
        : ['title' => __('Queue clear'), 'subtitle' => __('No deposits awaiting review'), 'tone' => 'success'];
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1">
    @include('filament.tenant.widgets.partials.insights-head', [
        'hero' => $hero,
        'kpis' => $kpis,
        'sparkline' => $d['pending'] > 0 ? $d['sparkline'] : null,
        'sparklineMax' => $sparkMax,
    ])

    @include('filament.tenant.widgets.partials.treasury-forecast-grid', ['forecast' => $d['treasury_forecast']])

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
                        <x-member::amount :value="$d['accepted_amount_this_month']" :currency="$currency" :precision="0" class="inline" /> {{ __('this mo') }}
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
                        <x-member::amount :value="$docs['pending_total']" :currency="$currency" :precision="0" />
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
                            <p class="truncate text-[10px] text-gray-400">{!! $posting['amount_display'] !!} ·
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
