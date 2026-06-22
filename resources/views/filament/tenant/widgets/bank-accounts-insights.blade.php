@php
    $d = $this->getData();
    $pollingInterval = method_exists($this, 'getPollingInterval') ? $this->getPollingInterval() : null;
    $maxStatus = max(1, collect($d['status_breakdown'])->max('count'));
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1" @if (filled($pollingInterval)) wire:poll.{{ $pollingInterval }} @endif>
    @if ($d['active_tab'] === 'queue')
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            @foreach ($d['clearing_kpis'] as $kpi)
                <a href="{{ $kpi['url'] }}"
                    class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm transition hover:border-sky-200 hover:bg-sky-50/40 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-sky-800/40 dark:hover:bg-sky-950/20">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ $kpi['label'] }}</p>
                    <p @class([
                        'mt-1 text-xl font-bold tabular-nums leading-none',
                        'text-amber-600 dark:text-amber-400' => ($kpi['accent'] ?? '') === 'amber',
                        'text-emerald-600 dark:text-emerald-400' => ($kpi['accent'] ?? '') === 'emerald',
                        'text-rose-600 dark:text-rose-400' => ($kpi['accent'] ?? '') === 'rose',
                        'text-sky-600 dark:text-sky-400' => ($kpi['accent'] ?? '') === 'sky',
                        'text-gray-900 dark:text-white' => ! in_array($kpi['accent'] ?? '', ['amber', 'emerald', 'rose', 'sky'], true),
                    ])>{{ $kpi['value'] }}</p>
                    <p class="mt-1 text-[10px] text-gray-500 dark:text-gray-400">{{ $kpi['sub'] }}</p>
                </a>
            @endforeach
        </div>
    @endif

    @include('filament.tenant.widgets.partials.insights-head', [
        'hero' => $d['hero'],
        'kpis' => $d['kpis'],
    ])

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center gap-1.5 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <x-heroicon-o-arrows-right-left class="h-4 w-4 text-indigo-500" />
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                    {{ __('Posting pipeline') }}
                </h3>
            </div>
            <div class="space-y-2 px-3 py-3">
                @foreach ($d['status_breakdown'] as $row)
                    @php $width = $maxStatus > 0 ? round(($row['count'] / $maxStatus) * 100) : 0; @endphp
                    <div>
                        <div class="mb-0.5 flex justify-between text-[10px]">
                            <span class="text-gray-600 dark:text-gray-300">{{ $row['label'] }}</span>
                            <span class="tabular-nums text-gray-400">{{ $row['count'] }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div @class(['h-full rounded-full', $row['color']])
                                style="width: {{ max($row['count'] > 0 ? 6 : 0, $width) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="border-t border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex justify-between text-[10px] text-gray-500">
                    <span>{{ __('Post rate') }}</span>
                    <span class="font-semibold text-emerald-600">{{ $d['post_rate'] }}%</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div class="flex flex-col gap-0.5 rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('Master cash') }}</p>
                <p class="text-[20px] font-bold tabular-nums leading-none text-gray-900 dark:text-white">
                    {{ \App\Support\Insights\InsightFormatter::compactAmount($d['master_cash']) }}
                </p>
                <p class="text-[10px] text-sky-600 dark:text-sky-400">
                    {!! \App\Support\Insights\InsightFormatter::moneyMarkup($d['master_cash']) !!}
                </p>
            </div>
            <div class="flex flex-col gap-0.5 rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('Master bank') }}</p>
                <p class="text-[20px] font-bold tabular-nums leading-none text-gray-900 dark:text-white">
                    {{ \App\Support\Insights\InsightFormatter::compactAmount($d['master_bank']) }}
                </p>
                <p class="text-[10px] text-indigo-600 dark:text-indigo-400">
                    {!! \App\Support\Insights\InsightFormatter::moneyMarkup($d['master_bank']) !!}
                </p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        @include('filament.partials.insights.six-month-dual-progress-panel', [
            'title' => __('Import volume'),
            'trend' => $d['trend'],
            'primaryLabel' => __('Imports'),
            'secondaryLabel' => __('Peak'),
        ])

        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-document-text class="h-4 w-4 text-sky-500" />
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('Recent statements') }}
                    </h4>
                </div>
                <a href="{{ $d['urls']['index'] }}"
                    class="text-[10px] font-medium text-sky-600 hover:underline dark:text-sky-400">{{ __('All') }}</a>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($d['recent_statements'] as $statement)
                    <a href="{{ $statement['url'] }}"
                        class="flex items-center gap-2 px-3 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-medium text-gray-900 dark:text-white">
                                {{ $statement['filename'] }}
                            </p>
                            <p class="truncate text-[10px] text-gray-400">
                                {{ $statement['bank_name'] ?? __('—') }} · {{ $statement['date'] }}
                                · {{ $statement['imported_rows'] }}/{{ $statement['total_rows'] }}
                            </p>
                        </div>
                        <span @class([
                            'shrink-0 rounded px-1.5 py-0.5 text-[9px] font-semibold uppercase',
                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40' => $statement['status'] === 'completed',
                            'bg-amber-100 text-amber-800 dark:bg-amber-900/40' => $statement['status'] === 'pending',
                            'bg-rose-100 text-rose-800 dark:bg-rose-900/40' => $statement['status'] === 'failed',
                            'bg-sky-100 text-sky-800 dark:bg-sky-900/40' => $statement['status'] === 'processing',
                        ])>{{ $statement['status_label'] }}</span>
                    </a>
                @empty
                    <div class="px-3 py-6 text-center text-xs text-gray-500">{{ __('No imports yet') }}</div>
                @endforelse
            </div>
        </div>
    </div>
</div>