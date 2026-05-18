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
        'gray' => 'bg-gray-400',
        'slate' => 'bg-slate-400',
    ];
    $accentIcon = [
        'amber' => 'text-amber-500',
        'emerald' => 'text-emerald-500',
        'rose' => 'text-rose-500',
        'sky' => 'text-sky-500',
        'violet' => 'text-violet-500',
        'teal' => 'text-teal-500',
        'indigo' => 'text-indigo-500',
        'gray' => 'text-gray-400',
        'slate' => 'text-slate-400',
    ];
@endphp

<div
    class="ff-app-insights-kpi-strip overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 lg:col-span-2">
    <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700 sm:grid-cols-6">
        @foreach ($kpis as $i => $card)
            @php
                $accent = $card['accent'] ?? 'gray';
                if ($accent === 'gray') {
                    $accent = 'slate';
                }
                $barClass = $accentBar[$accent] ?? 'bg-gray-400';
                $iconClass = $accentIcon[$accent] ?? 'text-gray-400';
                $tag = filled($card['url'] ?? null) ? 'a' : 'div';
            @endphp
            <{{ $tag }}
                @if ($tag === 'a')
                    href="{{ $card['url'] }}"
                @endif
                @class([
                    'ff-app-insights-kpi relative min-w-0 px-2 py-1.5 transition hover:bg-gray-50/80 dark:hover:bg-gray-800/60 sm:px-2.5 sm:py-2',
                ])
                style="animation: ff-stat-in 0.35s ease-out {{ 0.02 + ($i * 0.03) }}s forwards">
                <div @class(['absolute inset-y-0 left-0 w-0.5 opacity-100', $barClass])></div>
                <div class="flex items-center justify-between gap-0.5 pl-1">
                    <x-dynamic-component :component="$card['icon']" @class(['h-3 w-3 shrink-0 sm:h-3.5 sm:w-3.5', $iconClass]) />
                </div>
                <p class="mt-0.5 truncate pl-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">
                    {{ ui_label($card['label']) }}
                </p>
                <p @class([
                    'truncate pl-1 text-base font-bold tabular-nums leading-tight sm:text-lg',
                    $card['value_class'] ?? 'text-gray-900 dark:text-white',
                ])>
                    {{ $card['value'] }}
                </p>
                <p class="truncate pl-1 text-[10px] text-gray-400">{{ ui_label($card['sub']) }}</p>
            </{{ $tag }}>
        @endforeach
    </div>

    @if (filled($sparkline) && count($sparkline) > 0)
        <div class="flex h-4 items-end gap-px border-t border-gray-100 px-2 py-0.5 dark:border-gray-700 sm:h-5 sm:py-1">
            @foreach ($sparkline as $point)
                @php $h = max(20, (int) round(($point / $sparklineMax) * 100)); @endphp
                <div class="flex-1 rounded-sm bg-indigo-400/70 dark:bg-indigo-500/60" style="height: {{ $h }}%"></div>
            @endforeach
        </div>
    @endif
</div>