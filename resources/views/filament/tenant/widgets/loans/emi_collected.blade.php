@php
    $pipeline = $d['pipeline'];
    $currency = $d['currency'];
    $forecast = $d['forecast'];
@endphp

@include('filament.tenant.widgets.partials.insights-head', [
    'hero' => $d['hero'],
    'kpis' => $d['kpis'],
])

<div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
    <div class="rounded-xl border border-violet-200/80 bg-violet-50/60 px-3 py-3 shadow-sm dark:border-violet-800/40 dark:bg-violet-950/20">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">{{ __('Projected collected') }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums text-violet-900 dark:text-violet-100">{{ $forecast['projected_close_percent'] }}%</p>
        <p class="mt-1 text-[10px] text-violet-700/80 dark:text-violet-300/80">
            <x-member::amount :value="$forecast['projected_amount']" :currency="$currency" :precision="0" class="inline" />
        </p>
    </div>
    <div class="rounded-xl border border-sky-200/80 bg-sky-50/60 px-3 py-3 shadow-sm dark:border-sky-800/40 dark:bg-sky-950/20">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-300">{{ __('Days remaining') }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums text-sky-900 dark:text-sky-100">{{ $forecast['days_remaining'] }}</p>
        <p class="mt-1 text-[10px] text-sky-700/80 dark:text-sky-300/80">{{ __('By :date', ['date' => $forecast['deadline_label']]) }}</p>
    </div>
    <div class="rounded-xl border border-emerald-200/80 bg-emerald-50/60 px-3 py-3 shadow-sm dark:border-emerald-800/40 dark:bg-emerald-950/20">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-300">{{ __('Ready cash') }}</p>
        <p class="mt-1 text-xl font-bold tabular-nums text-emerald-900 dark:text-emerald-100">
            <x-member::amount :value="$forecast['ready_cash_total']" :currency="$currency" :precision="0" class="inline" />
        </p>
        <p class="mt-1 text-[10px] text-emerald-700/80 dark:text-emerald-300/80">{{ __('Available to collect now') }}</p>
    </div>
    <div class="rounded-xl border border-rose-200/80 bg-rose-50/60 px-3 py-3 shadow-sm dark:border-rose-800/40 dark:bg-rose-950/20">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-rose-600 dark:text-rose-300">{{ __('Uncovered amount') }}</p>
        <p class="mt-1 text-xl font-bold tabular-nums text-rose-900 dark:text-rose-100">
            <x-member::amount :value="$forecast['uncovered_amount']" :currency="$currency" :precision="0" class="inline" />
        </p>
        <p class="mt-1 text-[10px] text-rose-700/80 dark:text-rose-300/80">{{ __('Still outstanding this cycle') }}</p>
    </div>
</div>

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-slate-800">
    <div class="grid grid-cols-2 divide-x divide-gray-100 dark:divide-white/10">
        <a href="{{ $pipeline['collected_url'] }}"
            class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-emerald-50/60 dark:hover:bg-emerald-950/20">
            <span class="text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['collected_open_period'] }}</span>
            <span class="mt-1 text-[10px] text-gray-500">{{ __('Collected this period') }}</span>
        </a>
        <a href="{{ $pipeline['collect_url'] }}"
            class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-amber-50/60 dark:hover:bg-amber-950/20">
            <span class="text-2xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['missing_open_period'] }}</span>
            <span class="mt-1 text-[10px] text-gray-500">{{ __('Still to collect') }}</span>
        </a>
    </div>
</div>
