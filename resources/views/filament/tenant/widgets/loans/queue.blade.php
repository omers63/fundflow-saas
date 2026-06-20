@php
    $pipeline = $d['pipeline'];
    $currency = $d['currency'];
@endphp

@include('filament.tenant.widgets.partials.insights-head', [
    'hero' => $d['hero'],
    'kpis' => $d['kpis'],
])

<div class="grid grid-cols-1 gap-3 md:grid-cols-2">

    {{-- Queue stage counters --}}
    <div
        class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
            <x-heroicon-o-funnel class="h-4 w-4 text-sky-500" />
            <h3 class="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">
                {{ __('Queue stages') }}
            </h3>
        </div>
        <div class="grid grid-cols-2 divide-x divide-gray-100 dark:divide-gray-700">
            <a href="{{ $pipeline['queue_needs_decision_url'] }}" class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-amber-50/60 dark:hover:bg-amber-950/20
                    {{ $d['active_tab'] === 'needs_decision' ? 'bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                <span
                    class="text-2xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['needs_decision'] }}</span>
                <span class="mt-1 text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('Decision') }}</span>
            </a>
            <a href="{{ $pipeline['queue_ready_to_disburse_url'] }}" class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-sky-50/60 dark:hover:bg-sky-950/20
                    {{ $d['active_tab'] === 'ready_to_disburse' ? 'bg-sky-50/60 dark:bg-sky-950/20' : '' }}">
                <span
                    class="text-2xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['ready_to_disburse'] }}</span>
                <span class="mt-1 text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('Disburse') }}</span>
            </a>
        </div>
    </div>

    {{-- Queue preview list --}}
    <div
        class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
            <x-heroicon-o-queue-list class="h-4 w-4 text-amber-500" />
            <h4 class="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">
                {{ $d['tab_labels'][$d['active_tab']] ?? __('Queue') }}
            </h4>
        </div>
        <div class="divide-y divide-gray-50 dark:divide-gray-800">
            @forelse ($d['preview'] as $loan)
                <a href="{{ $loan['view_url'] }}"
                    class="flex items-center gap-3 px-4 py-2.5 transition hover:bg-gray-50 dark:hover:bg-gray-800/60">
                    <div
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-[10px] font-bold text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">
                        {{ $loan['queue'] ? '#' . $loan['queue'] : '·' }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[12px] font-medium text-gray-900 dark:text-white">{{ $loan['member'] }}</p>
                        <p class="truncate text-[10px] text-gray-400">
                            <x-member::amount :value="$loan['amount']" :currency="$currency" :precision="0"
                                class="inline" />
                            @if (!empty($loan['fund_tier']))· {{ $loan['fund_tier'] }}@endif
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        <span
                            class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400">{{ $loan['days_waiting'] }}d</span>
                    </div>
                </a>
            @empty
                <div class="flex flex-col items-center justify-center gap-2 px-4 py-8 text-center">
                    <x-heroicon-o-check-circle class="h-7 w-7 text-emerald-400" />
                    <p class="text-[11px] text-gray-400">{{ __('Nothing in this queue tab') }}</p>
                </div>
            @endforelse
        </div>
    </div>
</div>