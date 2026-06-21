@props(['kpis', 'sparkline' => null, 'sparklineMax' => 1])

@php
    $accentText = [
        'amber' => 'text-amber-600 dark:text-amber-400',
        'emerald' => 'text-emerald-600 dark:text-emerald-400',
        'rose' => 'text-red-600 dark:text-red-400',
        'sky' => 'text-sky-600 dark:text-sky-400',
        'violet' => 'text-violet-600 dark:text-violet-400',
        'teal' => 'text-teal-600 dark:text-teal-400',
        'indigo' => 'text-indigo-600 dark:text-indigo-400',
        'gray' => 'text-gray-500 dark:text-gray-400',
        'slate' => 'text-gray-500 dark:text-gray-400',
    ];
    $count = count($kpis);
    $gridCols = match (true) {
        $count <= 4 => 'grid-cols-2 sm:grid-cols-4',
        $count <= 6 => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6',
        default => 'grid-cols-2 sm:grid-cols-4 lg:grid-cols-7',
    };
@endphp

<div class="ff-app-insights-kpi-strip w-full min-w-0 grid {{ $gridCols }} gap-2.5">
    @foreach ($kpis as $i => $card)
        @php
            $accent = $card['accent'] ?? 'gray';
            $textClass = $accentText[$accent] ?? 'text-gray-500 dark:text-gray-400';
            $active = $card['active'] ?? true;
            $tag = filled($card['url'] ?? null) ? 'a' : 'div';
            $rawValue = $card['value'];
            $valueText = (string) $rawValue;
            $currency = $card['currency'] ?? null;
            $valuePrecision = (int) ($card['value_precision'] ?? 2);
            $valueCompact = (bool) ($card['value_compact'] ?? false);
            $subPrecision = (int) ($card['sub_precision'] ?? 2);
            $subText = (string) ($card['sub'] ?? '');
            $valueIsAmount = is_int($rawValue) || is_float($rawValue);

            if (!$valueIsAmount && is_string($rawValue) && is_numeric($rawValue)) {
                $valueIsAmount = true;
                $rawValue = str_contains($rawValue, '.') ? (float) $rawValue : (int) $rawValue;
            }

            $dimmed = !$active ? 'opacity-50' : '';
        @endphp
        <{{ $tag }}
            @if ($tag === 'a') href="{{ $card['url'] }}" @endif
            @class([
                'ff-app-insights-kpi group flex min-w-0 flex-col gap-0.5 overflow-hidden rounded-xl border border-gray-200 bg-white px-3 py-2.5 shadow-sm transition hover:-translate-y-0.5 hover:border-sky-200 hover:shadow-md dark:border-gray-700 dark:bg-gray-900 dark:hover:border-sky-700',
                $dimmed,
            ])
            style="animation: ff-stat-in 0.3s ease-out {{ 0.02 + ($i * 0.03) }}s forwards">
            <p class="truncate text-[10px] font-semibold uppercase tracking-wide text-gray-400">
                {{ ui_label($card['label']) }}
            </p>
            <div class="flex min-w-0 items-baseline gap-0.5 overflow-hidden">
                <x-ff-stat-line :amount="$valueIsAmount ? $rawValue : null" :text="$valueIsAmount ? null : $valueText"
                    :currency="$currency" :precision="$valuePrecision" :compact="$valueCompact" @class([
                        'min-w-0 flex-1 truncate',
                        $card['value_class'] ?? 'text-gray-900 dark:text-white',
                        'text-base font-bold tabular-nums leading-none sm:text-lg xl:text-[22px]',
                    ]) />
                @if (!empty($card['suffix'] ?? null))
                    <span class="shrink-0 text-[11px] font-normal text-gray-400">{{ $card['suffix'] }}</span>
                @endif
            </div>
            <x-ff-stat-line :text="$subText" :currency="$currency" :precision="$subPrecision" @class(['min-w-0 truncate', $textClass, 'text-[11px] font-medium']) />
        </{{ $tag }}>
    @endforeach
</div>