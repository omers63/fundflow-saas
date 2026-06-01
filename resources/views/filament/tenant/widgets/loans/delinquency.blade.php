@php
    $pipeline = $d['pipeline'];
@endphp

<div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
    @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $d['hero']])
    @include('filament.tenant.widgets.partials.insights-kpi-strip', ['kpis' => $d['kpis']])
</div>

<div
    class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
        <div class="flex items-center gap-1.5">
            <x-heroicon-o-exclamation-triangle class="h-4 w-4 text-rose-500" />
            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                {{ __('Collections attention') }}
            </h3>
        </div>
    </div>
    <div class="grid grid-cols-2 divide-x divide-gray-100 dark:divide-gray-700 sm:grid-cols-4">
        <a href="{{ $pipeline['delinquency_installments_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-rose-50/70 dark:hover:bg-rose-950/20">
            <span
                class="text-xl font-bold tabular-nums text-rose-600 dark:text-rose-400">{{ $pipeline['overdue_installments'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Installments') }}</span>
        </a>
        <a href="{{ $pipeline['delinquency_contributions_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-amber-50/70 dark:hover:bg-amber-950/20">
            <span
                class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['contribution_arrears'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Contributions') }}</span>
        </a>
        <a href="{{ $pipeline['delinquency_guarantor_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
            <span
                class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['guarantor_at_risk'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Guarantor risk') }}</span>
        </a>
        <a href="{{ $pipeline['delinquency_members_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-violet-50/70 dark:hover:bg-violet-950/20">
            <span
                class="text-xl font-bold tabular-nums text-violet-600 dark:text-violet-400">{{ $pipeline['delinquent_members'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Delinquent') }}</span>
        </a>
    </div>
</div>