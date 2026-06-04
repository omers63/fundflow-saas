@php
    $pipeline = $d['pipeline'];
@endphp

<div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
    @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $d['hero']])
    @include('filament.tenant.widgets.partials.insights-kpi-strip', ['kpis' => $d['kpis']])
</div>

<div
    class="overflow-hidden rounded-xl border border-amber-200/80 bg-gradient-to-br from-amber-50/90 via-white to-emerald-50/50 shadow-sm dark:border-amber-500/25 dark:from-amber-950/30 dark:via-gray-800 dark:to-emerald-950/20">
    <div class="flex items-center justify-between gap-2 border-b border-amber-100/80 px-3 py-2 dark:border-amber-900/40">
        <div class="flex items-center gap-1.5">
            <x-heroicon-o-arrow-path-rounded-square class="h-4 w-4 text-amber-600 dark:text-amber-400" />
            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-200">
                {{ __('Open period collection') }}
            </h3>
        </div>
        <span class="text-[10px] font-medium text-amber-700 dark:text-amber-300">{{ $d['open_period']['label'] }}</span>
    </div>
    <div class="grid grid-cols-2 divide-x divide-amber-100/80 dark:divide-amber-900/40 sm:grid-cols-4">
        <a href="{{ $pipeline['collect_url'] }}"
            @class([
                'flex flex-col items-center px-2 py-3 text-center transition',
                'bg-amber-100/60 dark:bg-amber-950/40' => ($pipeline['missing_open_period'] ?? 0) > 0,
                'hover:bg-amber-50/80 dark:hover:bg-amber-950/30' => ($pipeline['missing_open_period'] ?? 0) === 0,
            ])>
            <span class="text-xl font-bold tabular-nums text-amber-700 dark:text-amber-300">{{ $pipeline['missing_open_period'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-600 dark:text-gray-400">{{ __('To collect') }}</span>
        </a>
        <a href="{{ $pipeline['collected_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-emerald-50/70 dark:hover:bg-emerald-950/20">
            <span class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['posted_open_period'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Posted') }}</span>
        </a>
        <a href="{{ $pipeline['ledger_pending_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
            <span class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['pending_open_period'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Pending rows') }}</span>
        </a>
        <a href="{{ $pipeline['arrears_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-rose-50/80 dark:hover:bg-rose-950/30">
            <span class="text-xl font-bold tabular-nums text-rose-600 dark:text-rose-400">{{ $pipeline['arrears_periods'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Arrears') }}</span>
        </a>
    </div>
</div>
