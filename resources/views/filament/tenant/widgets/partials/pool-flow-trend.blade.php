@php
    $flow = $pool['flow_trend'] ?? null;
    $points = is_array($flow) ? ($flow['points'] ?? []) : [];
    $inflowSeries = is_array($flow) ? ($flow['inflow_series'] ?? []) : [];
    $outflowSeries = is_array($flow) ? ($flow['outflow_series'] ?? []) : [];
    $inLines = is_array($flow) ? ($flow['lines']['inflow'] ?? []) : [];
    $outLines = is_array($flow) ? ($flow['lines']['outflow'] ?? []) : [];

    $barTone = [
        'sky' => 'bg-sky-500 dark:bg-sky-400',
        'emerald' => 'bg-emerald-500 dark:bg-emerald-400',
        'amber' => 'bg-amber-500 dark:bg-amber-400',
        'rose' => 'bg-rose-500 dark:bg-rose-400',
        'violet' => 'bg-violet-500 dark:bg-violet-400',
    ];
    $lineStroke = [
        'sky' => '#0ea5e9',
        'emerald' => '#10b981',
        'amber' => '#f59e0b',
        'rose' => '#f43f5e',
        'violet' => '#8b5cf6',
    ];
@endphp

@if (count($points) > 0)
    <div class="border-t border-gray-100 px-4 py-3 dark:border-gray-700">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('12-cycle pool trend') }}
            </p>
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[10px] text-gray-500 dark:text-gray-400">
                @foreach ($inflowSeries as $series)
                    <span class="inline-flex items-center gap-1">
                        <span @class(['h-1.5 w-1.5 rounded-sm', $barTone[$series['color']] ?? 'bg-gray-400'])></span>
                        {{ $series['label'] }}
                    </span>
                @endforeach
                <span class="text-gray-300 dark:text-gray-600">|</span>
                @foreach ($outflowSeries as $series)
                    <span class="inline-flex items-center gap-1">
                        <span @class(['h-1.5 w-1.5 rounded-sm', $barTone[$series['color']] ?? 'bg-gray-400'])></span>
                        {{ $series['label'] }}
                    </span>
                @endforeach
            </div>
        </div>

        <div class="relative h-36 sm:h-40">
            {{-- Connecting lines (SVG coordinate space: mid-line y=50, top y=0, bottom y=100) --}}
            <svg class="pointer-events-none absolute inset-0 h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none"
                aria-hidden="true">
                <line x1="0" y1="50" x2="100" y2="50" class="stroke-gray-200 dark:stroke-gray-700" stroke-width="0.4"
                    vector-effect="non-scaling-stroke" />
                @foreach ($inflowSeries as $series)
                    @if (!empty($inLines[$series['key']]))
                        <polyline fill="none" stroke="{{ $lineStroke[$series['color']] ?? '#64748b' }}" stroke-width="0.9"
                            stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"
                            points="{{ $inLines[$series['key']] }}" opacity="0.85" />
                    @endif
                @endforeach
                @foreach ($outflowSeries as $series)
                    @if (!empty($outLines[$series['key']]))
                        <polyline fill="none" stroke="{{ $lineStroke[$series['color']] ?? '#64748b' }}" stroke-width="0.9"
                            stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"
                            points="{{ $outLines[$series['key']] }}" opacity="0.75" stroke-dasharray="2 1.5" />
                    @endif
                @endforeach
            </svg>

            <div class="absolute inset-0 flex items-stretch gap-px">
                @foreach ($points as $point)
                    @php
                        $inTooltip = __('Contributions: :c · EMI: :e', [
                            'c' => \App\Support\Insights\InsightFormatter::money($point['in']['contributions'] ?? 0),
                            'e' => \App\Support\Insights\InsightFormatter::money($point['in']['emi'] ?? 0),
                        ]);
                        $outTooltip = __('Loans: :l · Cash-outs: :o · Reserves: :r', [
                            'l' => \App\Support\Insights\InsightFormatter::money($point['out']['loans'] ?? 0),
                            'o' => \App\Support\Insights\InsightFormatter::money($point['out']['cash_outs'] ?? 0),
                            'r' => \App\Support\Insights\InsightFormatter::money($point['out']['reserves'] ?? 0),
                        ]);
                    @endphp
                    <div class="group relative flex min-w-0 flex-1 flex-col"
                        title="{{ ($point['period'] ?? $point['label'] ?? '') }} — {{ $inTooltip }} / {{ $outTooltip }}">
                        {{-- Positive half: inflow bars (grow upward) --}}
                        <div class="flex min-h-0 flex-1 items-end justify-center gap-px pb-px">
                            @foreach ($inflowSeries as $series)
                                @php $h = (float) ($point['in_heights'][$series['key']] ?? 0); @endphp
                                <div @class([
                                    'w-[2px] max-w-[3px] flex-1 rounded-t-sm sm:w-[3px]',
                                    $barTone[$series['color']] ?? 'bg-gray-400',
                                    'opacity-40' => $h <= 0,
                                    'opacity-90' => $h > 0,
                                ])
                                    style="height: {{ max($h > 0 ? 4 : 1, $h) }}%"></div>
                            @endforeach
                        </div>

                        {{-- Zero axis label (day) --}}
                        <div
                            class="flex h-3 shrink-0 items-center justify-center border-y border-gray-100 dark:border-gray-700/80">
                            <span
                                class="text-[8px] leading-none text-gray-400 tabular-nums dark:text-gray-500">{{ $point['label'] }}</span>
                        </div>

                        {{-- Negative half: outflow bars (grow downward) --}}
                        <div class="flex min-h-0 flex-1 items-start justify-center gap-px pt-px">
                            @foreach ($outflowSeries as $series)
                                @php $h = (float) ($point['out_heights'][$series['key']] ?? 0); @endphp
                                <div @class([
                                    'w-[2px] max-w-[3px] flex-1 rounded-b-sm sm:w-[3px]',
                                    $barTone[$series['color']] ?? 'bg-gray-400',
                                    'opacity-40' => $h <= 0,
                                    'opacity-90' => $h > 0,
                                ])
                                    style="height: {{ max($h > 0 ? 4 : 1, $h) }}%"></div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-1.5 flex items-center justify-between text-[9px] text-gray-400 dark:text-gray-500">
            <span>{{ __('Inflows') }} ↑</span>
            <span>{{ __('Outflows') }} ↓</span>
        </div>
    </div>
@endif