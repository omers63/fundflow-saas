@php
    $d = $this->getData();
    $pollingInterval = method_exists($this, 'getPollingInterval') ? $this->getPollingInterval() : null;
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1" @if (filled($pollingInterval)) wire:poll.{{ $pollingInterval }} @endif>
    @if (empty($d))
        <div
            class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            {{ __('Loading statement insights…') }}
        </div>
    @else
        @include('filament.tenant.widgets.partials.insights-head', [
            'hero' => $d['hero'],
            'kpis' => $d['kpis'],
            'sparkline' => $d['sparkline'],
            'sparklineMax' => $d['sparkline_max'],
        ])

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div
                    class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-chart-pie class="h-4 w-4 text-violet-500" />
                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ __('Line status') }}</h3>
                    </div>
                    <span class="text-[10px] text-gray-400">{{ $d['post_rate'] }}% {{ __('posted') }}</span>
                </div>
                <div class="grid grid-cols-2 gap-2 px-3 py-3 sm:grid-cols-5">
                    @foreach ($d['status_breakdown'] as $status)
                        <div class="rounded-lg border border-gray-100 px-2 py-2 text-center dark:border-gray-700">
                            <p class="text-[10px] text-gray-500">{{ $status['label'] }}</p>
                            <p class="text-sm font-bold tabular-nums text-gray-900 dark:text-white">{{ $status['count'] }}
                            </p>
                        </div>
                    @endforeach
                </div>
                <div class="border-t border-gray-100 px-3 py-2 dark:border-gray-700">
                    <div class="mb-1 flex justify-between text-[10px] text-gray-500">
                        <span>{{ __('Import progress') }}</span>
                        <span>{{ $d['imported_rows'] }} / {{ $d['total_rows'] }} {{ __('rows') }}</span>
                    </div>
                    @php
                        $importPct =
                            $d['total_rows'] > 0 ? min(100, round(($d['imported_rows'] / $d['total_rows']) * 100)) : 0;
                    @endphp
                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                        <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500"
                            style="width: {{ $importPct }}%"></div>
                    </div>
                </div>
            </div>

            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div
                    class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-banknotes class="h-4 w-4 text-emerald-500" />
                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ __('Recent lines') }}</h3>
                    </div>
                    <span class="text-[10px] text-gray-400">{{ __('Last 5') }}</span>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($d['recent'] as $line)
                        <li class="flex items-start justify-between gap-2 px-3 py-2 text-xs">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-800 dark:text-gray-200">{{ $line['description'] }}</p>
                                <p class="text-[10px] text-gray-400">
                                    {{ $line['date'] }} · {{ $line['status_label'] }}
                                    @if ($line['member'])
                                        · {{ $line['member'] }}
                                    @endif
                                </p>
                            </div>
                            <span @class(['shrink-0 font-semibold tabular-nums', $line['signed_class']])>
                                <x-ff-money-text :text="$line['amount']" />
                            </span>
                        </li>
                    @empty
                        <li class="px-3 py-4 text-center text-[11px] text-gray-400">{{ __('No lines in this file') }}
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    @endif
</div>
