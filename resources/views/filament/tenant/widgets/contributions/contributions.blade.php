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
        ['key' => 'arrears', 'label' => __('Arrears'), 'value' => $pipeline['arrears_periods'] ?? 0, 'sub' => __('Periods'), 'icon' => 'heroicon-o-exclamation-triangle', 'accent' => 'rose', 'active' => ($pipeline['arrears_periods'] ?? 0) > 0],
        ['key' => 'rate', 'label' => __('Collection'), 'value' => $open['collection_rate'] . '%', 'sub' => $open['label'], 'icon' => 'heroicon-o-chart-pie', 'accent' => 'violet', 'active' => true],
        ['key' => 'late', 'label' => __('Late'), 'value' => $d['late_count'], 'sub' => __('Pending'), 'icon' => 'heroicon-o-exclamation-triangle', 'accent' => 'teal', 'active' => $d['late_count'] > 0],
    ], [
        'pending' => $pipeline['contributions_pending_url'],
        'posted' => $pipeline['contributions_posted_url'],
        'failed' => $pipeline['contributions_failed_url'],
        'arrears' => $pipeline['arrears_url'],
        'rate' => $pipeline['cycle_url'],
        'late' => $pipeline['contributions_pending_url'],
    ]);
@endphp

@include('filament.tenant.widgets.partials.insights-head', [
    'hero' => $hero,
    'kpis' => $kpis,
    'sparkline' => $d['pending'] > 0 ? $d['sparkline'] : null,
    'sparklineMax' => $sparkMax,
])

<x-insights-forecast-details :title="__('Forecast & ledger details')">
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div class="rounded-xl border border-violet-200/80 bg-violet-50/60 px-3 py-3 shadow-sm dark:border-violet-800/40 dark:bg-violet-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">{{ __('Projected close') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-violet-900 dark:text-violet-100">{{ $forecast['projected_close_percent'] }}%</p>
        </div>
        <div class="rounded-xl border border-sky-200/80 bg-sky-50/60 px-3 py-3 shadow-sm dark:border-sky-800/40 dark:bg-sky-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-300">{{ __('Days remaining') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-sky-900 dark:text-sky-100">{{ $forecast['days_remaining'] }}</p>
        </div>
        <div class="rounded-xl border border-amber-200/80 bg-amber-50/60 px-3 py-3 shadow-sm dark:border-amber-800/40 dark:bg-amber-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-300">{{ __('Remaining amount') }}</p>
            <p class="mt-1 text-xl font-bold tabular-nums text-amber-900 dark:text-amber-100">
                <x-member::amount :value="$forecast['remaining_amount']" :currency="$currency" :precision="0" class="inline" />
            </p>
        </div>
        <div class="rounded-xl border border-emerald-200/80 bg-emerald-50/60 px-3 py-3 shadow-sm dark:border-emerald-800/40 dark:bg-emerald-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-300">{{ __('Required pace') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-900 dark:text-emerald-100">{{ $forecast['required_count_per_day'] }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <div class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-funnel class="h-4 w-4 text-emerald-500" />
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Pipeline') }}</h3>
                </div>
            </div>
            <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700">
                <a href="{{ $pipeline['contributions_pending_url'] }}" class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-amber-50/70 dark:hover:bg-amber-950/20">
                    <span class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['pending_contributions'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Pending') }}</span>
                </a>
                <a href="{{ $pipeline['contributions_posted_url'] }}" class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-emerald-50/70 dark:hover:bg-emerald-950/20">
                    <span class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['posted_contributions'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Posted') }}</span>
                </a>
                <a href="{{ $pipeline['arrears_url'] }}" class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-rose-50/70 dark:hover:bg-rose-950/20">
                    <span class="text-xl font-bold tabular-nums text-rose-600 dark:text-rose-400">{{ $pipeline['arrears_periods'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Arrears') }}</span>
                </a>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center gap-1.5 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <x-heroicon-o-queue-list class="h-4 w-4 text-amber-500" />
                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Review queue') }}</h4>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse (array_slice($d['oldest_pending'], 0, 3) as $posting)
                    <a href="{{ $posting['queue_url'] }}" class="flex items-center gap-2 px-3 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-medium text-gray-900 dark:text-white">{{ $posting['name'] }}</p>
                            <p class="truncate text-[10px] text-gray-400">{{ $posting['period_label'] }}</p>
                        </div>
                    </a>
                @empty
                    <p class="px-3 py-4 text-center text-xs text-gray-500">{{ __('Queue is empty') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</x-insights-forecast-details>
