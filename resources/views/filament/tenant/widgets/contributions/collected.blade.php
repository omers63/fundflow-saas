@php
    $pipeline = $d['pipeline'];
@endphp

@include('filament.tenant.widgets.partials.insights-head', [
    'hero' => $d['hero'],
    'kpis' => $d['kpis'],
])

<div
    class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-slate-800">
    <div class="grid grid-cols-2 divide-x divide-gray-100 dark:divide-white/10">
        <a href="{{ $pipeline['collected_url'] }}"
            class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-emerald-50/60 dark:hover:bg-emerald-950/20">
            <span
                class="text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['posted_open_period'] }}</span>
            <span class="mt-1 text-[10px] text-gray-500">{{ __('Posted this period') }}</span>
        </a>
        <a href="{{ $pipeline['collect_url'] }}"
            class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-amber-50/60 dark:hover:bg-amber-950/20">
            <span
                class="text-2xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['missing_open_period'] }}</span>
            <span class="mt-1 text-[10px] text-gray-500">{{ __('Still to collect') }}</span>
        </a>
    </div>
</div>