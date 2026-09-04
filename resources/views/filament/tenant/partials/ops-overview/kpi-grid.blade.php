{{--
  Compact KPI grid matching member workspace summary cells.
  $items: list of [
    label, value|amount, currency?, url?, value_class?, precision?, compact?
  ]
--}}
@php
    $items = $items ?? [];
    $count = count($items);
    $gridCols = match (true) {
        $count <= 1 => 'grid-cols-1',
        $count === 2 => 'grid-cols-2',
        $count === 3 => 'grid-cols-2 lg:grid-cols-3',
        default => 'grid-cols-2 lg:grid-cols-4',
    };
@endphp

@if ($items !== [])
    <div @class(['ff-ops-overview__kpi-grid grid gap-2', $gridCols])>
        @foreach ($items as $item)
            @php
                $url = $item['url'] ?? null;
                $tag = filled($url) ? 'a' : 'div';
                $amount = $item['amount'] ?? null;
                $value = $item['value'] ?? null;
                $currency = $item['currency'] ?? null;
                $precision = (int) ($item['precision'] ?? 2);
                $compact = (bool) ($item['compact'] ?? false);
                $valueClass = $item['value_class']
                    ?? 'text-lg font-extrabold tabular-nums tracking-tight text-gray-900 sm:text-xl dark:text-white';
                $hint = $item['hint'] ?? null;
            @endphp
            <{{ $tag }}
                @if ($tag === 'a') href="{{ $url }}" @endif
                @class([
                    'ff-ops-overview__kpi block min-w-0 overflow-hidden rounded-lg border border-gray-200/90 px-3 py-2.5 transition dark:border-white/10',
                    'hover:bg-gray-50 dark:hover:bg-gray-800/80' => $tag === 'a',
                ])
            >
                <p class="truncate text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    {{ $item['label'] ?? '' }}
                </p>
                @if ($amount !== null)
                    <x-ff-stat-line
                        :amount="$amount"
                        :currency="$currency"
                        :precision="$precision"
                        :compact="$compact"
                        @class(['mt-0.5', $valueClass])
                    />
                @else
                    <p @class(['mt-0.5 truncate', $valueClass])>
                        {{ $value ?? '—' }}
                    </p>
                @endif
                @if (filled($hint))
                    <p class="mt-0.5 truncate text-[10px] text-gray-400 dark:text-gray-500">
                        {!! \App\Filament\Support\MoneyDisplay::markupForDisplay($hint, $currency) !!}
                    </p>
                @endif
            </{{ $tag }}>
        @endforeach
    </div>
@endif
