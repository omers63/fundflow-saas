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
    <div class="grid grid-cols-2 divide-x divide-gray-100 dark:divide-gray-700 sm:grid-cols-4 lg:grid-cols-7">
        @foreach ($kpis as $i => $card)
            @php
                $accent = $card['accent'] ?? 'gray';
                if ($accent === 'gray') {
                    $accent = 'slate';
                }
                $barClass = $accentBar[$accent] ?? 'bg-gray-400';
                $iconClass = $accentIcon[$accent] ?? 'text-gray-400';
                $barOpacity = ($card['active'] ?? true) ? 'opacity-100' : 'opacity-25';
                $tag = filled($card['url'] ?? null) ? 'a' : 'div';
                $labelText = ui_label($card['label']);
                $subText = ui_label($card['sub']);
                $valueText = (string) $card['value'];
                if (!empty($card['suffix'] ?? null)) {
                    $valueText .= ' ' . $card['suffix'];
                }
            @endphp
            <{{ $tag }}
                @if ($tag === 'a')
                    href="{{ $card['url'] }}"
                @endif
                @class([
                    'ff-app-insights-kpi ff-tenant-stat-card relative min-w-0 px-2.5 py-2 transition sm:px-2.5 sm:py-2',
                    'cursor-pointer hover:bg-gray-50/80 dark:hover:bg-gray-800/60' => $tag === 'a',
                ])
                data-accent="{{ $accent }}"
                style="animation: ff-stat-in 0.35s ease-out {{ 0.02 + ($i * 0.03) }}s forwards">
                <div @class(['absolute inset-y-0 left-0 w-0.5', $barClass, $barOpacity])></div>
                <div class="flex items-center justify-between gap-1 pl-1">
                    <x-dynamic-component :component="$card['icon']" @class(['h-3.5 w-3.5 shrink-0', $iconClass]) />
                    @if (($card['key'] ?? null) === 'new' && isset($card['mom']) && $card['mom'] !== null)
                        <span @class([
                            'text-[9px] font-bold',
                            'text-emerald-600 dark:text-emerald-400' => $card['mom'] >= 0,
                            'text-rose-600 dark:text-rose-400' => $card['mom'] < 0,
                        ])>{{ $card['mom'] >= 0 ? '↑' : '↓' }}{{ abs($card['mom']) }}%</span>
                    @endif
                </div>
                <x-ff-stat-line :text="$labelText"
                    class="mt-0.5 truncate pl-1 text-[10px] font-medium uppercase tracking-wide text-gray-500" />
                <x-ff-stat-line :text="$valueText" @class([
                    'truncate pl-1 text-lg font-bold tabular-nums leading-tight',
                    $card['value_class'] ?? 'text-gray-900 dark:text-white',
                ])>
                    {{ $card['value'] }}@if (!empty($card['suffix'] ?? null))<span
                    class="text-[10px] font-normal text-gray-400">{{ $card['suffix'] }}</span>@endif
                </x-ff-stat-line>
                <x-ff-stat-line :text="$subText" class="truncate pl-1 text-[10px] text-gray-400" />
            </{{ $tag }}>
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