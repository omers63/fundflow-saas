    @php
        $flow = $pool['flow_trend'] ?? null;
        $points = is_array($flow) ? ($flow['points'] ?? []) : [];
        $inflowSeries = is_array($flow) ? ($flow['inflow_series'] ?? []) : [];
        $outflowSeries = is_array($flow) ? ($flow['outflow_series'] ?? []) : [];
        $inLines = is_array($flow) ? ($flow['lines']['inflow'] ?? []) : [];
        $outLines = is_array($flow) ? ($flow['lines']['outflow'] ?? []) : [];

        // Fixed half-chart height in px so bars never collapse when % height is ignored on flex items.
        $halfPx = 88;

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
                        <span @class(['h-2 w-2 rounded-sm', $barTone[$series['color']] ?? 'bg-gray-400'])></span>
                        {{ $series['label'] }}
                    </span>
                @endforeach
                <span class="text-gray-300 dark:text-gray-600">|</span>
                @foreach ($outflowSeries as $series)
                    <span class="inline-flex items-center gap-1">
                        <span @class(['h-2 w-2 rounded-sm', $barTone[$series['color']] ?? 'bg-gray-400'])></span>
                        {{ $series['label'] }}
                    </span>
                @endforeach
            </div>
        </div>

        <div class="relative overflow-hidden rounded-md bg-gray-50/80 ring-1 ring-inset ring-gray-100 dark:bg-gray-800/40 dark:ring-gray-700/80">
            {{-- Connecting lines (SVG coordinate space: mid-line y=50, top y=0, bottom y=100) --}}
            <svg class="pointer-events-none absolute inset-0 z-10 h-full w-full" viewBox="0 0 100 100"
                preserveAspectRatio="none" aria-hidden="true">
                <line x1="0" y1="50" x2="100" y2="50" class="stroke-gray-300 dark:stroke-gray-600" stroke-width="0.6"
                    vector-effect="non-scaling-stroke" />
                @foreach ($inflowSeries as $series)
                    @if (!empty($inLines[$series['key']]))
                        <polyline fill="none" stroke="{{ $lineStroke[$series['color']] ?? '#64748b' }}" stroke-width="1.6"
                            stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"
                            points="{{ $inLines[$series['key']] }}" opacity="0.95" />
                    @endif
                @endforeach
                @foreach ($outflowSeries as $series)
                    @if (!empty($outLines[$series['key']]))
                        <polyline fill="none" stroke="{{ $lineStroke[$series['color']] ?? '#64748b' }}" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"
                            points="{{ $outLines[$series['key']] }}" opacity="0.9" stroke-dasharray="2.5 1.5" />
                    @endif
                @endforeach
            </svg>

            <div class="relative z-0 flex items-stretch gap-0.5 px-1 py-1 sm:gap-1">
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
                        {{-- Inflows (grow up from center) — pixel heights --}}
                        <div class="flex items-end justify-center gap-0.5"
                            style="height: {{ $halfPx }}px">
                            @foreach ($inflowSeries as $series)
                                @php
                                    $pct = (float) ($point['in_heights'][$series['key']] ?? 0);
                                    $px = $pct > 0 ? max(6, (int) round(($pct / 100) * $halfPx)) : 2;
                                @endphp
                                <div @class([
                                    'w-[4px] max-w-[7px] flex-1 rounded-t-sm sm:w-[5px]',
                                    $barTone[$series['color']] ?? 'bg-gray-400',
                                    'opacity-30' => $pct <= 0,
                                    'opacity-100' => $pct > 0,
                                ])
                                    style="height: {{ $px }}px"></div>
                            @endforeach
                        </div>

                        {{-- Zero axis label --}}
                        <div
                            class="flex h-4 shrink-0 items-center justify-center border-y border-gray-200/90 bg-white/60 dark:border-gray-600 dark:bg-gray-900/40">
                            <span
                                class="text-[8px] font-semibold leading-none text-gray-600 tabular-nums dark:text-gray-300">{{ $point['label'] }}</span>
                        </div>

                        {{-- Outflows (grow down from center) — pixel heights --}}
                        <div class="flex items-start justify-center gap-0.5"
                            style="height: {{ $halfPx }}px">
                            @foreach ($outflowSeries as $series)
                                @php
                                    $pct = (float) ($point['out_heights'][$series['key']] ?? 0);
                                    $px = $pct > 0 ? max(6, (int) round(($pct / 100) * $halfPx)) : 2;
                                @endphp
                                <div @class([
                                    'w-[4px] max-w-[7px] flex-1 rounded-b-sm sm:w-[5px]',
                                    $barTone[$series['color']] ?? 'bg-gray-400',
                                    'opacity-30' => $pct <= 0,
                                    'opacity-100' => $pct > 0,
                                ])
                                    style="height: {{ $px }}px"></div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-1.5 flex items-center justify-between text-[9px] font-medium text-gray-500 dark:text-gray-400">
            <span>{{ __('Inflows') }} ↑</span>
            <span>{{ __('Outflows') }} ↓</span>
        </div>
    </div>
@endif
