@php
    $pipeline = $d['pipeline'];
    $currency = $d['currency'];
@endphp

<div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
    @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $d['hero']])
    @include('filament.tenant.widgets.partials.insights-kpi-strip', ['kpis' => $d['kpis']])
</div>

<div class="grid grid-cols-1 gap-3 md:grid-cols-2">
    <div
        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-funnel class="h-4 w-4 text-sky-500" />
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Queue stages') }}
                </h3>
            </div>
        </div>
        <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700">
            <a href="{{ $pipeline['queue_needs_decision_url'] }}" @class(['flex flex-col items-center px-2 py-3 text-center transition', 'bg-amber-50/80 dark:bg-amber-950/30' => $d['active_tab'] === 'needs_decision'])>
                <span class="text-xl font-bold tabular-nums text-amber-600">{{ $pipeline['needs_decision'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Decision') }}</span>
            </a>
            <a href="{{ $pipeline['queue_ready_to_disburse_url'] }}" @class(['flex flex-col items-center px-2 py-3 text-center transition', 'bg-sky-50/80 dark:bg-sky-950/30' => $d['active_tab'] === 'ready_to_disburse'])>
                <span class="text-xl font-bold tabular-nums text-sky-600">{{ $pipeline['ready_to_disburse'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Disburse') }}</span>
            </a>
            <a href="{{ $pipeline['queue_awaiting_payout_url'] }}" @class(['flex flex-col items-center px-2 py-3 text-center transition', 'bg-indigo-50/80 dark:bg-indigo-950/30' => $d['active_tab'] === 'awaiting_payout'])>
                <span class="text-xl font-bold tabular-nums text-indigo-600">{{ $pipeline['awaiting_payout'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Payout') }}</span>
            </a>
        </div>
    </div>

    <div
        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-queue-list class="h-4 w-4 text-amber-500" />
                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                    {{ $d['tab_labels'][$d['active_tab']] ?? __('Queue') }}</h4>
            </div>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @forelse ($d['preview'] as $loan)
                <a href="{{ $loan['view_url'] }}"
                    class="flex items-center gap-2 px-3 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-sky-100 text-[10px] font-bold text-sky-800 dark:bg-sky-900/40">
                        {{ $loan['queue'] ? '#' . $loan['queue'] : '·' }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-medium text-gray-900 dark:text-white">{{ $loan['member'] }}</p>
                        <p class="truncate text-[10px] text-gray-400">{{ number_format($loan['amount'], 0) }}
                            {{ $currency }} · {{ $loan['fund_tier'] ?? '—' }}</p>
                    </div>
                    <span class="shrink-0 text-[10px] font-medium text-gray-500">{{ $loan['days_waiting'] }}d</span>
                </a>
            @empty
                <div class="px-3 py-6 text-center">
                    <x-heroicon-o-check-circle class="mx-auto h-6 w-6 text-emerald-400" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Nothing in this queue tab') }}</p>
                </div>
            @endforelse
        </div>
    </div>
</div>