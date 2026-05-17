@php
    $currency = $d['currency'];
@endphp

<div class="grid grid-cols-1 gap-3 md:grid-cols-3">
    @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $d['hero']])
    <div
        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 md:col-span-2">
        <div class="grid grid-cols-2 divide-x divide-gray-100 dark:divide-gray-700 sm:grid-cols-4">
            @foreach ($d['kpis'] as $card)
                <div class="ff-app-insights-kpi px-2.5 py-2">
                    <p class="text-[10px] font-medium uppercase tracking-wide text-gray-500">{{ ui_label($card['label']) }}</p>
                    <p class="text-lg font-bold tabular-nums text-gray-900 dark:text-white">{{ $card['value'] }}</p>
                    <p class="text-[10px] text-gray-400">{{ ui_label($card['sub']) }}</p>
                </div>
            @endforeach
        </div>
    </div>
</div>