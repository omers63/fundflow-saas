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
            <x-heroicon-o-banknotes class="h-4 w-4 text-amber-600 dark:text-amber-400" />
            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-200">
                {{ __('Open period EMI collection') }}
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
            <span class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['collected_open_period'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Collected') }}</span>
        </a>
        <a href="{{ $pipeline['collect_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-teal-50/70 dark:hover:bg-teal-950/20">
            <span class="text-xl font-bold tabular-nums text-teal-600 dark:text-teal-400">{{ $pipeline['ready_with_cash'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Ready (cash)') }}</span>
        </a>
        <a href="{{ $pipeline['overdue_url'] }}"
            class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-rose-50/80 dark:hover:bg-rose-950/30">
            <span class="text-xl font-bold tabular-nums text-rose-600 dark:text-rose-400">{{ $pipeline['overdue_installments'] }}</span>
            <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Overdue') }}</span>
        </a>
    </div>
</div>

<div
    class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
        <div class="flex items-center gap-1.5">
            <x-heroicon-o-user-group class="h-4 w-4 text-amber-500" />
            <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                {{ __('Members to collect') }}
            </h4>
        </div>
        @if (($pipeline['missing_open_period'] ?? 0) > 0)
            <span class="text-[10px] text-gray-400">{{ $pipeline['required_cash'] }} {{ __('required cash') }}</span>
        @endif
    </div>
    <div class="divide-y divide-gray-100 dark:divide-gray-700">
        @forelse ($d['preview'] as $row)
            <a href="{{ $row['filter_url'] }}"
                class="flex items-center gap-2 px-3 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                <span
                    @class([
                        'flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-[10px] font-bold',
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40' => $row['has_cash'],
                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40' => ! $row['has_cash'],
                    ])>
                    {{ $row['pending_emis'] }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-xs font-medium text-gray-900 dark:text-white">{{ $row['member'] }}</p>
                    <p class="truncate text-[10px] text-gray-400">
                        {{ $row['required_cash'] }}
                        · {{ $row['has_cash'] ? __('Cash ready') : __('Insufficient cash') }}
                    </p>
                </div>
            </a>
        @empty
            <div class="px-3 py-6 text-center">
                <x-heroicon-o-check-circle class="mx-auto h-6 w-6 text-emerald-400" />
                <p class="mt-1 text-xs text-gray-500">{{ __('No members with EMIs to collect') }}</p>
            </div>
        @endforelse
    </div>
</div>
