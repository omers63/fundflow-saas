@php
    $maxCount = $d['max_count'];
    $currency = $d['currency'];
@endphp

<div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
    @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $d['hero']])
    @include('filament.tenant.widgets.partials.insights-kpi-strip', ['kpis' => $d['kpis']])
</div>

<div
    class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-700">
        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
            {{ __('Amount bands & active loans') }}</p>
    </div>
    <div class="grid gap-px bg-gray-100 dark:bg-gray-700 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($d['breakdown'] as $tier)
            @php $width = $maxCount > 0 ? round(($tier['count'] / $maxCount) * 100) : 0; @endphp
            <div class="bg-white px-3 py-2.5 dark:bg-gray-800">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ $tier['label'] }}</p>
                        <p class="text-[10px] text-gray-400">{{ $tier['range'] }}</p>
                    </div>
                    <span
                        class="rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-bold text-sky-800 dark:bg-sky-900/40">{{ $tier['count'] }}</span>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                    <div class="h-full rounded-full bg-gradient-to-r from-sky-500 to-indigo-500"
                        style="width: {{ max($tier['count'] > 0 ? 8 : 0, $width) }}%"></div>
                </div>
                <p class="mt-1 text-[10px] text-gray-500">{{ __('Min installment') }}: {{ $tier['min_installment'] }}</p>
            </div>
        @endforeach
    </div>
</div>