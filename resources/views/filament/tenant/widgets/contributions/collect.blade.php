@php
    $pipeline = $d['pipeline'];
    $forecast = $d['forecast'];
@endphp

@include('filament.tenant.widgets.partials.insights-head', [
    'hero' => $d['hero'],
    'kpis' => $d['kpis'],
])

<div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
    <div class="rounded-xl border border-violet-200/80 bg-violet-50/60 px-3 py-3 shadow-sm dark:border-violet-800/40 dark:bg-violet-950/20">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">{{ __('Projected close') }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums text-violet-900 dark:text-violet-100">{{ $forecast['projected_close_percent'] }}%</p>
        <p class="mt-1 text-[10px] text-violet-700/80 dark:text-violet-300/80">
            {{ __('Projected amount') }}:
            <x-member::amount :value="$forecast['projected_amount']" :currency="$d['currency']" :precision="0" class="inline" />
        </p>
    </div>
    <div class="rounded-xl border border-sky-200/80 bg-sky-50/60 px-3 py-3 shadow-sm dark:border-sky-800/40 dark:bg-sky-950/20">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-300">{{ __('Days remaining') }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums text-sky-900 dark:text-sky-100">{{ $forecast['days_remaining'] }}</p>
        <p class="mt-1 text-[10px] text-sky-700/80 dark:text-sky-300/80">{{ __('By :date', ['date' => $forecast['deadline_label']]) }}</p>
    </div>
    <div class="rounded-xl border border-amber-200/80 bg-amber-50/60 px-3 py-3 shadow-sm dark:border-amber-800/40 dark:bg-amber-950/20">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-300">{{ __('Remaining members') }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums text-amber-900 dark:text-amber-100">{{ $forecast['remaining_count'] }}</p>
        <p class="mt-1 text-[10px] text-amber-700/80 dark:text-amber-300/80">{{ __('Current pace') }}: {{ $forecast['current_count_per_day'] }}/{{ __('day') }}</p>
    </div>
    <div class="rounded-xl border border-emerald-200/80 bg-emerald-50/60 px-3 py-3 shadow-sm dark:border-emerald-800/40 dark:bg-emerald-950/20">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-300">{{ __('Required pace') }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-900 dark:text-emerald-100">{{ $forecast['required_count_per_day'] }}</p>
        <p class="mt-1 text-[10px] text-emerald-700/80 dark:text-emerald-300/80">
            <x-member::amount :value="$forecast['required_amount_per_day']" :currency="$d['currency']" :precision="0" class="inline" /> / {{ __('day') }}
        </p>
    </div>
</div>

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
        <div class="flex items-center gap-2">
            <x-heroicon-o-arrow-path-rounded-square class="h-4 w-4 text-sky-500" />
            <h3 class="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">
                {{ __('Open period collection') }}
            </h3>
        </div>
        <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-[10px] font-semibold text-sky-700 ring-1 ring-inset ring-sky-200 dark:bg-sky-950/40 dark:text-sky-300">
            {{ $d['open_period']['label'] }}
        </span>
    </div>
    <div class="grid grid-cols-2 divide-x divide-gray-100 dark:divide-gray-700 sm:grid-cols-4">
        <a href="{{ $pipeline['collect_url'] }}" @class([
            'flex flex-col items-center px-3 py-4 text-center transition hover:bg-amber-50/60 dark:hover:bg-amber-950/20',
            'bg-amber-50/40 dark:bg-amber-950/10' => ($pipeline['missing_open_period'] ?? 0) > 0,
        ])>
            <span class="text-2xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['missing_open_period'] }}</span>
            <span class="mt-1 text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('To collect') }}</span>
        </a>
        <a href="{{ $pipeline['collected_url'] }}" class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-emerald-50/60 dark:hover:bg-emerald-950/20">
            <span class="text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['posted_open_period'] }}</span>
            <span class="mt-1 text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('Posted') }}</span>
        </a>
        <a href="{{ $pipeline['ledger_pending_url'] }}" class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-sky-50/60 dark:hover:bg-sky-950/20">
            <span class="text-2xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['pending_open_period'] }}</span>
            <span class="mt-1 text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('Pending rows') }}</span>
        </a>
        <a href="{{ $pipeline['arrears_url'] }}" class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-red-50/60 dark:hover:bg-red-950/20">
            <span class="text-2xl font-bold tabular-nums text-red-600 dark:text-red-400">{{ $pipeline['arrears_periods'] }}</span>
            <span class="mt-1 text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('Arrears') }}</span>
        </a>
    </div>
</div>
