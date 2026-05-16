@props(['kpis', 'sparkline' => null, 'sparklineMax' => 1])

@php
    $accentBar = [
        'amber' => 'bg-amber-500',
        'emerald' => 'bg-emerald-500',
        'rose' => 'bg-rose-500',
        'sky' => 'bg-sky-500',
        'violet' => 'bg-violet-500',
        'teal' => 'bg-teal-500',
        'indigo' => 'bg-indigo-500',
    ];
    $accentIcon = [
        'amber' => 'text-amber-500',
        'emerald' => 'text-emerald-500',
        'rose' => 'text-rose-500',
        'sky' => 'text-sky-500',
        'violet' => 'text-violet-500',
        'teal' => 'text-teal-500',
        'indigo' => 'text-indigo-500',
    ];
@endphp

<div
    class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 lg:col-span-2">
    <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700 sm:grid-cols-6">
        @foreach ($kpis as $i => $card)
            @php
                $barClass = $accentBar[$card['accent']] ?? 'bg-gray-400';
                $iconClass = $accentIcon[$card['accent']] ?? 'text-gray-400';
            @endphp
            <div class="ff-app-insights-kpi relative px-2.5 py-2 transition hover:bg-gray-50/80 dark:hover:bg-gray-800/60"
                style="animation: ff-stat-in 0.35s ease-out {{ 0.02 + ($i * 0.03) }}s both">
                <div @class(['absolute inset-y-0 left-0 w-0.5 opacity-100', $barClass])></div>
                <div class="flex items-center justify-between gap-1 pl-1">
                    <x-dynamic-component :component="$card['icon']" @class(['h-3.5 w-3.5', $iconClass]) />
                </div>
                <p class="mt-0.5 pl-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">
                    {{ $card['label'] }}</p>
                <p @class([
                    'pl-1 text-lg font-bold tabular-nums leading-tight',
                    $card['value_class'] ?? 'text-gray-900 dark:text-white',
                ])>{{ $card['value'] }}</p>
                <p class="pl-1 text-[10px] text-gray-400">{{ $card['sub'] }}</p>
            </div>
        @endforeach
    </div>
    @if (filled($sparkline) && count($sparkline) > 0)
        <div class="flex h-5 items-end gap-px border-t border-gray-100 px-2 py-1 dark:border-gray-700">
            @foreach ($sparkline as $point)
                @php $h = max(20, (int) round(($point / $sparklineMax) * 100)); @endphp
                <div class="flex-1 rounded-sm bg-indigo-400/70 dark:bg-indigo-500/60" style="height: {{ $h }}%"></div>
            @endforeach
        </div>
    @endif
</div>
