@php
    $pipeline = $d['pipeline'];
    $maxGateCount = max(1, (int) ($d['max_gate_count'] ?? 1));
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
                <x-heroicon-o-funnel class="h-4 w-4 text-amber-500" />
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                    {{ __('Review outcomes') }}
                </h3>
            </div>
        </div>
        <div class="grid grid-cols-2 divide-x divide-gray-100 dark:divide-gray-700 sm:grid-cols-4">
            <a href="{{ $pipeline['pending_url'] }}"
                @class([
                    'flex flex-col items-center px-2 py-3 text-center transition',
                    'bg-amber-50/80 dark:bg-amber-950/30' => ($pipeline['pending'] ?? 0) > 0,
                    'hover:bg-amber-50/70 dark:hover:bg-amber-950/20' => ($pipeline['pending'] ?? 0) === 0,
                ])>
                <span class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['pending'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Pending') }}</span>
            </a>
            <a href="{{ $pipeline['approved_url'] }}"
                class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-emerald-50/70 dark:hover:bg-emerald-950/20">
                <span class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['approved'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Approved') }}</span>
            </a>
            <a href="{{ $pipeline['rejected_url'] }}"
                class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-rose-50/70 dark:hover:bg-rose-950/20">
                <span class="text-xl font-bold tabular-nums text-rose-600 dark:text-rose-400">{{ $pipeline['rejected'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Rejected') }}</span>
            </a>
            <a href="{{ $pipeline['overrides_url'] }}"
                class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
                <span class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['standing_overrides'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Overrides') }}</span>
            </a>
        </div>
    </div>

    <div
        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-shield-exclamation class="h-4 w-4 text-violet-500" />
                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                    {{ __('Pending reviews') }}
                </h4>
            </div>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @forelse ($d['preview'] as $review)
                <a href="{{ $review['filter_url'] }}"
                    class="flex items-center gap-2 px-3 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-[10px] font-bold text-amber-800 dark:bg-amber-900/40">
                        {{ $review['days_waiting'] }}d
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-medium text-gray-900 dark:text-white">{{ $review['member'] }}</p>
                        <p class="truncate text-[10px] text-gray-400">{{ $review['blocked_rules'] }}</p>
                    </div>
                </a>
            @empty
                <div class="px-3 py-6 text-center">
                    <x-heroicon-o-check-circle class="mx-auto h-6 w-6 text-emerald-400" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('No pending eligibility reviews') }}</p>
                </div>
            @endforelse
        </div>
    </div>
</div>

@if (count($d['top_blocked_rules'] ?? []) > 0)
    <div
        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                {{ __('Most common blocked rules (pending)') }}
            </p>
        </div>
        <div class="space-y-1.5 px-3 py-2.5">
            @foreach ($d['top_blocked_rules'] as $row)
                @php $width = round(($row['count'] / $maxGateCount) * 100); @endphp
                <div>
                    <div class="mb-0.5 flex justify-between text-[10px]">
                        <span class="text-gray-600 dark:text-gray-300">{{ $row['label'] }}</span>
                        <span class="tabular-nums text-gray-400">{{ $row['count'] }}</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                        <div class="h-full rounded-full bg-gradient-to-r from-amber-500 to-violet-500"
                            style="width: {{ max($row['count'] > 0 ? 6 : 0, $width) }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
