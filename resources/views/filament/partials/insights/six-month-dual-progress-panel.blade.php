@props([
    'title',
    'trend' => [],
    'primaryLabel' => __('Members'),
    'secondaryLabel' => __('Amount'),
    'icon' => 'heroicon-o-chart-bar',
    'compact' => false,
    'headerStat' => null,
    'headerAside' => null,
])

<div
    class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div @class([
        'flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 dark:border-gray-700',
        'px-3 py-2' => ! $compact,
        'px-3 py-1.5' => $compact,
    ])>
        <div class="flex items-center gap-1.5">
            <x-dynamic-component :component="$icon" @class([
                'text-indigo-500',
                'h-4 w-4' => ! $compact,
                'h-3.5 w-3.5' => $compact,
            ]) />
            <h4 @class([
                'font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400',
                'text-[11px]' => ! $compact,
                'text-[10px]' => $compact,
            ])>{{ $title }}</h4>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($headerStat)
                <span class="text-[10px] font-medium text-emerald-700 dark:text-emerald-300">{{ $headerStat }}</span>
            @endif
            @if ($headerAside)
                {{ $headerAside }}
            @endif
            <div class="flex flex-wrap gap-2 text-[10px] text-gray-500">
                <span class="flex items-center gap-1"><span
                        class="h-2 w-2 rounded-sm bg-emerald-500"></span>{{ __('≥90%') }}</span>
                <span class="flex items-center gap-1"><span
                        class="h-2 w-2 rounded-sm bg-amber-400"></span>{{ __('50–89%') }}</span>
                <span class="flex items-center gap-1"><span
                        class="h-2 w-2 rounded-sm bg-rose-500"></span>{{ __('<50%') }}</span>
            </div>
        </div>
    </div>
    <div @class([
        'px-3 py-2.5' => ! $compact,
        'px-2.5 py-2' => $compact,
    ])>
        <ul class="grid grid-cols-2 gap-x-3 gap-y-2.5">
            @foreach ($trend as $month)
                @include('filament.partials.insights.dual-progress-trend-row', [
                    'month' => $month,
                    'primaryLabel' => $primaryLabel,
                    'secondaryLabel' => $secondaryLabel,
                ])
            @endforeach
        </ul>
    </div>
</div>
