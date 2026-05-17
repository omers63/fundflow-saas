@php
    $currency = $d['currency'];
@endphp

<div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
    @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $d['hero']])
    @include('filament.tenant.widgets.partials.insights-kpi-strip', ['kpis' => $d['kpis']])
</div>

<div class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
        <div class="flex items-center gap-1.5">
            <x-heroicon-o-circle-stack class="h-4 w-4 text-indigo-500" />
            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Pool utilization by tier') }}</h3>
        </div>
        <a href="{{ $d['queue_url'] }}" class="text-[10px] font-semibold text-sky-600 hover:underline">{{ __('Open queue') }}</a>
    </div>
    <div class="divide-y divide-gray-100 dark:divide-gray-700">
        @foreach ($d['breakdown'] as $tier)
            <div class="px-3 py-2.5">
                <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-gray-900 dark:text-white">
                            {{ $tier['label'] }}
                            @if ($tier['is_emergency'])
                                <span class="ml-1 rounded bg-rose-100 px-1 py-0.5 text-[9px] font-bold uppercase text-rose-700 dark:bg-rose-900/40">{{ __('Emergency') }}</span>
                            @endif
                        </p>
                        <p class="text-[10px] text-gray-400">{{ $tier['loan_tier'] ?? __('—') }} · {{ $tier['percentage'] }}%</p>
                    </div>
                    <p class="text-[10px] tabular-nums text-gray-500">{{ $tier['active_loans'] }} {{ __('loans') }}</p>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                    <div @class(['h-full rounded-full', 'bg-rose-500' => $tier['used_percent'] >= 85, 'bg-amber-500' => $tier['used_percent'] >= 60 && $tier['used_percent'] < 85, 'bg-emerald-500' => $tier['used_percent'] < 60]) style="width: {{ max(4, $tier['used_percent']) }}%"></div>
                </div>
                <div class="mt-1 flex justify-between text-[10px] text-gray-500">
                    <span>{{ __('Deployed') }}: {{ number_format($tier['exposure'], 0) }}</span>
                    <span class="text-emerald-600">{{ __('Available') }}: {{ number_format($tier['available'], 0) }} {{ $currency }}</span>
                </div>
            </div>
        @endforeach
    </div>
</div>
