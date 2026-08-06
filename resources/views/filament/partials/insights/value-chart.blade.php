@php
    $colorMap = [
        'sky' => ['fill' => '#0ea5e9', 'bar' => 'bg-sky-500 dark:bg-sky-400', 'text' => 'text-sky-700 dark:text-sky-300'],
        'emerald' => ['fill' => '#10b981', 'bar' => 'bg-emerald-500 dark:bg-emerald-400', 'text' => 'text-emerald-700 dark:text-emerald-300'],
        'amber' => ['fill' => '#f59e0b', 'bar' => 'bg-amber-500 dark:bg-amber-400', 'text' => 'text-amber-700 dark:text-amber-300'],
        'orange' => ['fill' => '#f97316', 'bar' => 'bg-orange-500 dark:bg-orange-400', 'text' => 'text-orange-700 dark:text-orange-300'],
        'rose' => ['fill' => '#f43f5e', 'bar' => 'bg-rose-500 dark:bg-rose-400', 'text' => 'text-rose-700 dark:text-rose-300'],
        'red' => ['fill' => '#ef4444', 'bar' => 'bg-red-500 dark:bg-red-400', 'text' => 'text-red-700 dark:text-red-300'],
        'violet' => ['fill' => '#8b5cf6', 'bar' => 'bg-violet-500 dark:bg-violet-400', 'text' => 'text-violet-700 dark:text-violet-300'],
        'indigo' => ['fill' => '#6366f1', 'bar' => 'bg-indigo-500 dark:bg-indigo-400', 'text' => 'text-indigo-700 dark:text-indigo-300'],
        'teal' => ['fill' => '#14b8a6', 'bar' => 'bg-teal-500 dark:bg-teal-400', 'text' => 'text-teal-700 dark:text-teal-300'],
        'slate' => ['fill' => '#94a3b8', 'bar' => 'bg-slate-400 dark:bg-slate-500', 'text' => 'text-slate-600 dark:text-slate-300'],
    ];
    $type = $chart['type'] ?? 'bars';
    $asMoney = (bool) ($chart['as_money'] ?? false);
@endphp

<div
    class="ff-value-chart overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <div
        class="flex flex-wrap items-start justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
        <div class="min-w-0">
            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {{ $chart['title'] ?? '' }}
            </h3>
            @if (filled($chart['subtitle'] ?? null))
                <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">{{ $chart['subtitle'] }}</p>
            @endif
        </div>
        @if (filled($chart['center'] ?? null) && $type === 'donut')
            <span
                class="shrink-0 text-sm font-bold tabular-nums text-gray-900 dark:text-white">{{ $chart['center'] }}</span>
        @endif
    </div>

    <div class="p-3" dir="ltr">
        @if ($type === 'donut')
            @php
                $circumference = 100;
                $radius = 15.5;
            @endphp
            <div class="flex flex-col items-center gap-3 sm:flex-row sm:items-center">
                <div class="relative h-28 w-28 shrink-0">
                    <svg class="h-full w-full -rotate-90" viewBox="0 0 36 36" aria-hidden="true">
                        <circle cx="18" cy="18" r="{{ $radius }}" fill="none" class="stroke-gray-100 dark:stroke-gray-800"
                            stroke-width="3.5" />
                        @foreach (($chart['segments'] ?? []) as $segment)
                            @php
                                $share = (float) ($segment['share'] ?? 0);
                                $offset = (float) ($segment['offset'] ?? 0);
                                $fill = $colorMap[$segment['color'] ?? 'slate']['fill'] ?? '#94a3b8';
                            @endphp
                            @if ($share > 0)
                                <circle cx="18" cy="18" r="{{ $radius }}" fill="none" stroke="{{ $fill }}" stroke-width="3.5"
                                    stroke-linecap="butt" stroke-dasharray="{{ $share }} {{ max(0, $circumference - $share) }}"
                                    stroke-dashoffset="{{ -$offset }}" pathLength="100" />
                            @endif
                        @endforeach
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span
                            class="text-sm font-bold tabular-nums text-gray-900 dark:text-white">{{ $chart['center'] ?? '—' }}</span>
                    </div>
                </div>
                <ul class="min-w-0 flex-1 space-y-1.5">
                    @foreach (($chart['segments'] ?? []) as $segment)
                        <li class="flex items-center justify-between gap-2 text-xs">
                            <span class="inline-flex min-w-0 items-center gap-1.5">
                                <span
                                    class="h-2 w-2 shrink-0 rounded-sm {{ $colorMap[$segment['color'] ?? 'slate']['bar'] ?? 'bg-slate-400' }}"></span>
                                <span class="truncate text-gray-600 dark:text-gray-300">{{ $segment['label'] }}</span>
                            </span>
                            <span class="shrink-0 font-semibold tabular-nums text-gray-900 dark:text-white">
                                @if ($asMoney)
                                    {!! \App\Support\Insights\InsightFormatter::moneyCompactMarkup((float) $segment['value']) !!}
                                @else
                                    {{ number_format((float) $segment['value'], 0) }}
                                    <span class="ms-1 text-[10px] font-medium text-gray-400">{{ $segment['share'] }}%</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @elseif ($type === 'stack')
            <div class="mb-2 flex h-4 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                @foreach (($chart['segments'] ?? []) as $segment)
                    @if (($segment['share'] ?? 0) > 0)
                        <div class="{{ $colorMap[$segment['color'] ?? 'slate']['bar'] ?? 'bg-slate-400' }} h-full"
                            style="width: {{ $segment['share'] }}%" title="{{ $segment['label'] }}"></div>
                    @endif
                @endforeach
            </div>
            <ul class="space-y-1.5">
                @foreach (($chart['segments'] ?? []) as $segment)
                    <li class="flex items-center justify-between gap-2 text-xs">
                        <span class="inline-flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                            <span
                                class="h-2 w-2 rounded-sm {{ $colorMap[$segment['color'] ?? 'slate']['bar'] ?? 'bg-slate-400' }}"></span>
                            {{ $segment['label'] }}
                        </span>
                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">
                            @if ($asMoney)
                                {!! \App\Support\Insights\InsightFormatter::moneyMarkup((float) $segment['value']) !!}
                            @else
                                {{ number_format((float) $segment['value'], 0) }}
                            @endif
                            <span class="ms-1 text-[10px] font-medium text-gray-400">{{ $segment['share'] }}%</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @elseif ($type === 'funnel')
            <div class="space-y-2">
                @foreach (($chart['steps'] ?? []) as $step)
                    <div>
                        <div class="mb-0.5 flex items-center justify-between text-[11px]">
                            <span class="font-medium text-gray-600 dark:text-gray-300">{{ $step['label'] }}</span>
                            <span
                                class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format((float) $step['value'], 0) }}</span>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div class="{{ $colorMap[$step['color'] ?? 'sky']['bar'] ?? 'bg-sky-500' }} h-full rounded-full transition-all"
                                style="width: {{ max(4, $step['width'] ?? 0) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @elseif ($type === 'aging')
            <div class="space-y-2.5">
                @foreach (($chart['buckets'] ?? []) as $bucket)
                    <div>
                        <div class="mb-0.5 flex flex-wrap items-center justify-between gap-1 text-[11px]">
                            <span class="font-medium text-gray-600 dark:text-gray-300">{{ $bucket['label'] }}</span>
                            <span class="tabular-nums text-gray-500">
                                {{ number_format((int) $bucket['count']) }} ·
                                {!! \App\Support\Insights\InsightFormatter::moneyMarkup((float) $bucket['amount']) !!}
                            </span>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div class="{{ $colorMap[$bucket['color'] ?? 'amber']['bar'] ?? 'bg-amber-500' }} h-full rounded-full"
                                style="width: {{ max(($bucket['amount'] ?? 0) > 0 ? 4 : 0, $bucket['width'] ?? 0) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @elseif ($type === 'levels')
            <div class="flex h-36 items-end gap-2 sm:gap-3">
                @foreach (($chart['points'] ?? []) as $point)
                    <div class="flex min-w-0 flex-1 flex-col items-center gap-1">
                        <span class="text-[9px] font-semibold tabular-nums text-gray-500">
                            {!! \App\Support\Insights\InsightFormatter::moneyCompactMarkup((float) $point['value']) !!}
                        </span>
                        <div class="flex h-24 w-full items-end justify-center rounded-md bg-gray-50 dark:bg-gray-800/50">
                            <div class="w-full max-w-[2.5rem] rounded-t-md {{ $colorMap[$point['color'] ?? 'sky']['bar'] ?? 'bg-sky-500' }}"
                                style="height: {{ max(4, $point['height'] ?? 0) }}%"></div>
                        </div>
                        <span class="line-clamp-2 text-center text-[9px] font-medium text-gray-500">{{ $point['label'] }}</span>
                    </div>
                @endforeach
            </div>
        @else {{-- bars --}}
            <div class="space-y-2">
                @foreach (($chart['rows'] ?? []) as $row)
                    <div>
                        <div class="mb-0.5 flex items-center justify-between gap-2 text-[11px]">
                            <span class="min-w-0 truncate font-medium text-gray-600 dark:text-gray-300"
                                title="{{ $row['label'] }}{{ filled($row['sub'] ?? null) ? ' · ' . $row['sub'] : '' }}">
                                {{ $row['label'] }}
                            </span>
                            <span class="shrink-0 font-semibold tabular-nums text-gray-900 dark:text-white">
                                @if ($asMoney)
                                    {!! \App\Support\Insights\InsightFormatter::moneyMarkup((float) $row['value']) !!}
                                @else
                                    {{ number_format((float) $row['value'], 0) }}
                                @endif
                            </span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div class="{{ $colorMap[$row['color'] ?? 'sky']['bar'] ?? 'bg-sky-500' }} h-full rounded-full"
                                style="width: {{ max(($row['value'] ?? 0) != 0 ? 3 : 0, $row['width'] ?? 0) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>