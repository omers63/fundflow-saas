@php
    use App\Filament\Support\MoneyDisplay;

    $pipeline = $d['pipeline'];
    $cycle = $d['cycle'];
    $open = $d['open_period'];
    $forecast = $d['forecast'];
    $maxMethod = max(1, collect($d['method_breakdown'])->max('count'));
    $sparkMax = max(1, max($d['sparkline']));
    $currency = $cycle['currency'];
@endphp
    
    @php
        $hero = $d['pending'] > 0
            ? [
                'title' => __('Contributions need your attention'),
                'subtitle' => trans_choice(':count pending', $d['pending'], ['count' => $d['pending']])
                    . ' · ' . (MoneyDisplay::format($d['pending_amount_total'], $currency, precision: 0) ?? '')
                    . ($d['late_count'] > 0 ? ' · ' . trans_choice(':count late', $d['late_count'], ['count' => $d['late_count']]) : ''),
                'tone' => 'amber',
                'cta_url' => $pipeline['contributions_pending_url'],
                'cta_label' => __('Review'),
            ]
            : ['title' => __('Cycle on track'), 'subtitle' => __('No pending contributions to review'), 'tone' => 'success'];

        $kpis = \App\Support\Insights\InsightKpi::linkMany([
            ['key' => 'pending', 'label' => __('Pending'), 'value' => $d['pending'], 'sub' => __('Awaiting post'), 'icon' => 'heroicon-o-clock', 'accent' => 'amber', 'active' => $d['pending'] > 0],
            ['key' => 'posted', 'label' => __('Posted'), 'value' => $d['posted'], 'sub' => __(':count/mo', ['count' => $d['posted_this_month']]), 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true],
            ['key' => 'failed', 'label' => __('Failed'), 'value' => $d['failed'], 'sub' => __('All time'), 'icon' => 'heroicon-o-x-circle', 'accent' => 'rose', 'active' => $d['failed'] > 0],
            ['key' => 'new', 'label' => __('New/mo'), 'value' => $d['new_this_month'], 'sub' => $d['mom_change'] !== null ? __(':percent%', ['percent' => $d['mom_change']]) : now()->format('M'), 'icon' => 'heroicon-o-sparkles', 'accent' => 'sky', 'active' => true, 'mom' => $d['mom_change']],
            ['key' => 'rate', 'label' => __('Collection'), 'value' => $open['collection_rate'] . '%', 'sub' => $open['label'], 'icon' => 'heroicon-o-chart-pie', 'accent' => 'violet', 'active' => true],
            ['key' => 'late', 'label' => __('Late'), 'value' => $d['late_count'], 'sub' => __('Pending'), 'icon' => 'heroicon-o-exclamation-triangle', 'accent' => 'teal', 'active' => $d['late_count'] > 0],
        ], [
            'pending' => $pipeline['contributions_pending_url'],
            'posted' => $pipeline['contributions_posted_url'],
            'failed' => $pipeline['contributions_failed_url'],
            'new' => $pipeline['contributions_url'],
            'rate' => $pipeline['cycle_url'],
            'missing' => $pipeline['cycle_url'],
            'late' => $pipeline['contributions_pending_url'],
        ]);
    @endphp
    
    @include('filament.tenant.widgets.partials.insights-head', [
        'hero' => $hero,
        'kpis' => $kpis,
        'sparkline' => $d['pending'] > 0 ? $d['sparkline'] : null,
        'sparklineMax' => $sparkMax,
    ])

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div class="rounded-xl border border-violet-200/80 bg-violet-50/60 px-3 py-3 shadow-sm dark:border-violet-800/40 dark:bg-violet-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">{{ __('Projected close') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-violet-900 dark:text-violet-100">{{ $forecast['projected_close_percent'] }}%</p>
            <p class="mt-1 text-[10px] text-violet-700/80 dark:text-violet-300/80">
                {{ __('Projected amount') }}:
                <x-member::amount :value="$forecast['projected_amount']" :currency="$currency" :precision="0" class="inline" />
            </p>
        </div>
        <div class="rounded-xl border border-sky-200/80 bg-sky-50/60 px-3 py-3 shadow-sm dark:border-sky-800/40 dark:bg-sky-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-300">{{ __('Days remaining') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-sky-900 dark:text-sky-100">{{ $forecast['days_remaining'] }}</p>
            <p class="mt-1 text-[10px] text-sky-700/80 dark:text-sky-300/80">{{ __('By :date', ['date' => $forecast['deadline_label']]) }}</p>
        </div>
        <div class="rounded-xl border border-amber-200/80 bg-amber-50/60 px-3 py-3 shadow-sm dark:border-amber-800/40 dark:bg-amber-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-300">{{ __('Remaining amount') }}</p>
            <p class="mt-1 text-xl font-bold tabular-nums text-amber-900 dark:text-amber-100">
                <x-member::amount :value="$forecast['remaining_amount']" :currency="$currency" :precision="0" class="inline" />
            </p>
            <p class="mt-1 text-[10px] text-amber-700/80 dark:text-amber-300/80">
                {{ trans_choice(':count member remaining|:count members remaining', $forecast['remaining_count'], ['count' => $forecast['remaining_count']]) }}
            </p>
        </div>
        <div class="rounded-xl border border-emerald-200/80 bg-emerald-50/60 px-3 py-3 shadow-sm dark:border-emerald-800/40 dark:bg-emerald-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-300">{{ __('Required pace') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-900 dark:text-emerald-100">{{ $forecast['required_count_per_day'] }}</p>
            <p class="mt-1 text-[10px] text-emerald-700/80 dark:text-emerald-300/80">
                <x-member::amount :value="$forecast['required_amount_per_day']" :currency="$currency" :precision="0" class="inline" /> / {{ __('day') }}
            </p>
        </div>
    </div>

<div class="grid grid-cols-1 gap-3 md:grid-cols-2">
    <div
        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-funnel class="h-4 w-4 text-emerald-500" />
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Pipeline') }}
                </h3>
            </div>
            @if ($d['posted_amount_this_month'] > 0)
                <span class="text-[10px] font-medium text-emerald-700 dark:text-emerald-300">
                    <x-member::amount :value="$d['posted_amount_this_month']" :currency="$currency" :precision="0" class="inline" /> {{ __('this mo') }}
                </span>
            @endif
        </div>
        <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700">
            <a href="{{ $pipeline['contributions_pending_url'] }}"
                class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-amber-50/70 dark:hover:bg-amber-950/20">
                <span
                    class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['pending_contributions'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Pending') }}</span>
            </a>
            <a href="{{ $pipeline['contributions_posted_url'] }}"
                class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-emerald-50/70 dark:hover:bg-emerald-950/20">
                <span
                    class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['posted_contributions'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Posted') }}</span>
            </a>
            <a href="{{ $pipeline['cycle_url'] }}"
                class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
                <span
                    class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['missing_open_period'] }}</span>
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
                    {{ __('Payment methods') }}</p>
                <div class="mt-2 space-y-1.5">
                    @foreach ($d['method_breakdown'] as $tier)
                        @php $width = $maxMethod > 0 ? round(($tier['count'] / $maxMethod) * 100) : 0; @endphp
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
                    <x-heroicon-o-arrow-path-rounded-square class="h-3.5 w-3.5 text-emerald-500" />
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('Open cycle') }}</p>
                </div>
                <p class="mt-1.5 truncate text-lg font-bold tabular-nums text-gray-900 dark:text-white">
                    <x-member::amount :value="$cycle['pending_total']" :currency="$currency" :precision="0" />
                </p>
                <p class="text-[10px] text-gray-400">
                    {{ trans_choice(':count late pending|:count late pending', $cycle['late_count'], ['count' => $cycle['late_count']]) }}
                </p>
                <div class="mt-2">
                    <div class="mb-0.5 flex justify-between text-[10px] text-gray-500">
                        <span>{{ __('Collection rate') }}</span>
                        <span class="font-semibold text-emerald-600">{{ $cycle['collection_rate'] }}%</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                        <div class="h-full rounded-full bg-emerald-500" style="width: {{ $cycle['collection_rate'] }}%">
                        </div>
                    </div>
                </div>
                <div class="mt-2">
                    <div class="mb-0.5 flex justify-between text-[10px] text-gray-500">
                        <span>{{ __('Missing members') }}</span>
                        <span class="font-semibold text-sky-600">{{ $open['missing_members'] }}</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                        <div class="h-full rounded-full bg-sky-500"
                            style="width: {{ min(100, $open['missing_members'] * 10) }}%">
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
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-queue-list class="h-4 w-4 text-amber-500" />
                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                    {{ __('Review queue') }}</h4>
            </div>
            @if ($d['late_count'] > 0)
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
                        <p class="truncate text-[10px] text-gray-400">
                            {{ $posting['period_label'] }} ·
                            <x-member::amount :value="$posting['amount']" :currency="$currency" :precision="0" class="inline" /> ·
                            {{ $posting['is_late'] ? __('Late') : __('On time') }}
                        </p>
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

    @include('filament.partials.insights.six-month-dual-progress-panel', [
    'title' => __('6-month contribution outcomes'),
    'trend' => $d['trend'],
])
</div>
