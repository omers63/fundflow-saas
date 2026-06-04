@php
    $pipeline = $d['pipeline'];
@endphp

<div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
    @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $d['hero']])
    @include('filament.tenant.widgets.partials.insights-kpi-strip', ['kpis' => $d['kpis']])
</div>

<div
    class="overflow-hidden rounded-xl border border-emerald-200/70 bg-gradient-to-br from-emerald-50/80 via-white to-teal-50/40 shadow-sm dark:border-emerald-500/25 dark:from-emerald-950/30 dark:via-gray-800 dark:to-teal-950/20">
    <div class="grid grid-cols-2 divide-x divide-emerald-100 dark:divide-emerald-900/40">
        <a href="{{ $pipeline['collected_url'] }}"
            class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-emerald-50/80 dark:hover:bg-emerald-950/30">
            <span
                class="text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['posted_open_period'] }}</span>
            <span class="mt-1 text-[10px] text-gray-500">{{ __('Posted this period') }}</span>
        </a>
        <a href="{{ $pipeline['collect_url'] }}"
            class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-amber-50/70 dark:hover:bg-amber-950/20">
            <span
                class="text-2xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['missing_open_period'] }}</span>
            <span class="mt-1 text-[10px] text-gray-500">{{ __('Still to collect') }}</span>
        </a>
    </div>
</div>