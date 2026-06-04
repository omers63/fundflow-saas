@php
    $pipeline = $d['pipeline'];
@endphp

<div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
    @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $d['hero']])
    @include('filament.tenant.widgets.partials.insights-kpi-strip', ['kpis' => $d['kpis']])
</div>

<div
    class="overflow-hidden rounded-xl border border-rose-200/80 bg-gradient-to-br from-rose-50/90 via-amber-50/40 to-white shadow-sm dark:border-rose-500/30 dark:from-rose-950/40 dark:via-amber-950/20 dark:to-gray-800">
    <div class="flex items-center justify-between gap-2 border-b border-rose-100/80 px-3 py-2 dark:border-rose-900/40">
        <div class="flex items-center gap-1.5">
            <x-heroicon-o-exclamation-triangle class="h-4 w-4 text-rose-600 dark:text-rose-400" />
            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-rose-800 dark:text-rose-200">
                {{ __('Collections attention') }}
            </h3>
        </div>
    </div>
    <div class="grid grid-cols-2 divide-x divide-rose-100/80 dark:divide-rose-900/40 sm:grid-cols-4">
        <a href="{{ $pipeline['arrears_url'] }}"
            @class([
                'flex flex-col items-center px-2 py-3 text-center transition',
                'bg-rose-100/70 dark:bg-rose-950/40' => ($pipeline['arrears_periods'] ?? 0) > 0,
                'hover:bg-rose-50/80 dark:hover:bg-rose-950/30',
            ])>
            <span class="text-xl font-bold tabular-nums text-rose-700 dark:text-rose-300">{{ $pipeline['arrears_periods'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-600 dark:text-gray-400">{{ __('Arrears') }}</span>
        </a>
        <a href="{{ $pipeline['arrears_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-amber-50/70 dark:hover:bg-amber-950/20">
            <span class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['arrears_members'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Members') }}</span>
        </a>
        <a href="{{ $pipeline['delinquent_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-violet-50/70 dark:hover:bg-violet-950/20">
            <span class="text-xl font-bold tabular-nums text-violet-600 dark:text-violet-400">{{ $pipeline['delinquent_members'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Delinquent') }}</span>
        </a>
        <a href="{{ $pipeline['collect_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
            <span class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['overdue_installments'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Overdue EMIs') }}</span>
        </a>
    </div>
</div>
