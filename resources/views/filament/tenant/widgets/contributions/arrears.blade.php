@php
    $pipeline = $d['pipeline'];
@endphp

@include('filament.tenant.widgets.partials.insights-head', [
    'hero' => $d['hero'],
    'kpis' => $d['kpis'],
])

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-slate-800">
    <div class="flex items-center gap-1.5 border-b border-gray-100 px-3 py-2 dark:border-white/10">
        <x-heroicon-o-exclamation-triangle class="h-4 w-4 text-rose-500" />
        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
            {{ __('Collections attention') }}
        </h3>
    </div>
    <div class="grid grid-cols-2 divide-x divide-gray-100 dark:divide-white/10 sm:grid-cols-4">
        <a href="{{ $pipeline['arrears_url'] }}"
            @class([
                'flex flex-col items-center px-2 py-3 text-center transition hover:bg-rose-50/60 dark:hover:bg-rose-950/20',
                'bg-rose-50 dark:bg-rose-950/20' => ($pipeline['arrears_periods'] ?? 0) > 0,
            ])>
            <span class="text-xl font-bold tabular-nums text-rose-600 dark:text-rose-400">{{ $pipeline['arrears_periods'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Arrears') }}</span>
        </a>
        <a href="{{ $pipeline['arrears_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-amber-50/60 dark:hover:bg-amber-950/20">
            <span class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['arrears_members'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Members') }}</span>
        </a>
        <a href="{{ $pipeline['delinquent_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-violet-50/60 dark:hover:bg-violet-950/20">
            <span class="text-xl font-bold tabular-nums text-violet-600 dark:text-violet-400">{{ $pipeline['delinquent_members'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Delinquent') }}</span>
        </a>
        <a href="{{ $pipeline['collect_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-sky-50/60 dark:hover:bg-sky-950/20">
            <span class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['overdue_installments'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Overdue EMIs') }}</span>
        </a>
    </div>
</div>
