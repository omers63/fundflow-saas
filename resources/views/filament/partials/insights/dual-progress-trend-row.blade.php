@props([
    'month',
    'primaryLabel' => __('Members'),
    'secondaryLabel' => __('Amount'),
])
@php
    $fillTone = fn(string $tone): string => match ($tone) {
        'success' => 'bg-emerald-500',
        'warning' => 'bg-amber-400',
        'danger' => 'bg-rose-500',
        default => 'bg-gray-300 dark:bg-gray-600',
    };
    $textTone = fn(string $tone): string => match ($tone) {
        'success' => 'text-emerald-700 dark:text-emerald-300',
        'warning' => 'text-amber-700 dark:text-amber-300',
        'danger' => 'text-rose-700 dark:text-rose-300',
        default => 'text-gray-500',
    };
@endphp

<li class="min-w-0 space-y-1" title="{{ $month['tooltip'] }}">
    <div class="flex min-w-0 items-start gap-1.5">
        <span
            class="w-7 shrink-0 pt-0.5 text-[10px] font-semibold text-gray-600 dark:text-gray-300">{{ $month['label'] }}</span>
        <div class="min-w-0 flex-1 space-y-1">
        <div class="flex min-w-0 items-center gap-1.5">
            <span class="w-11 shrink-0 text-[9px] text-gray-400">{{ $primaryLabel }}</span>
                <div class="relative h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700/60">
                    @if (($month['expected_count'] ?? 0) > 0 && ($month['collection_rate'] ?? 0) > 0)
                        <div class="{{ $fillTone($month['tone']) }} h-full rounded-full"
                            style="width: {{ $month['collection_rate_bar'] }}%"></div>
                    @endif
                </div>
                <span
                    class="w-8 shrink-0 text-end text-[10px] font-semibold tabular-nums {{ $textTone($month['tone']) }}">
                    {{ ($month['expected_count'] ?? 0) > 0 ? $month['collection_rate'] . '%' : '—' }}
                </span>
            </div>
        <div class="flex min-w-0 items-center gap-1.5">
            <span class="w-11 shrink-0 text-[9px] text-gray-400">{{ $secondaryLabel }}</span>
                <div class="relative h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700/60">
                    @if (($month['expected_amount'] ?? 0) > 0 && ($month['amount_collection_rate'] ?? 0) > 0)
                        <div class="{{ $fillTone($month['amount_tone']) }} h-full rounded-full"
                            style="width: {{ $month['amount_collection_rate_bar'] }}%"></div>
                    @endif
                </div>
                <span
                    class="w-8 shrink-0 text-end text-[10px] font-semibold tabular-nums {{ $textTone($month['amount_tone']) }}">
                    {{ ($month['expected_amount'] ?? 0) > 0 ? $month['amount_collection_rate'] . '%' : '—' }}
                </span>
            </div>
            <p class="truncate text-[8px] tabular-nums text-gray-400" title="{{ $month['subtitle'] }}">
                {!! \App\Filament\Support\MoneyDisplay::markupForDisplay($month['subtitle']) !!}
            </p>
        </div>
    </div>
</li>
