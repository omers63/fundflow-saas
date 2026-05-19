@php
    $d = $this->getData();
    $sparkMax = max(1, max($d['sparkline'] ?? [1]));
@endphp

@if (! empty($d))
    <div class="ff-app-insights w-full max-w-none space-y-3 mb-1">
        <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
            <div @class([
                'overflow-hidden rounded-xl border px-3 py-2.5 shadow-sm lg:col-span-1',
                'border-amber-200/80 bg-gradient-to-r from-amber-50 to-emerald-50/80 dark:border-amber-500/30 dark:from-amber-950/40 dark:to-emerald-950/20' => $d['pending'] > 0,
                'border-emerald-200/70 bg-gradient-to-r from-emerald-50 to-teal-50/60 dark:border-emerald-500/25 dark:from-emerald-950/30 dark:to-teal-950/20' => $d['pending'] === 0,
            ])>
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-gray-900 dark:text-white">
                            {{ $d['pending'] > 0 ? __('Deposits awaiting review') : __('All deposits reviewed') }}
                        </p>
                        <p class="text-[11px] text-gray-500">
                            {{ trans_choice(':count pending|:count pending', $d['pending'], ['count' => $d['pending']]) }}
                            @if ($d['pending'] > 0)
                                · {{ $d['pending_amount'] }}
                            @endif
                        </p>
                    </div>
                    <a href="{{ $d['create_url'] }}"
                        class="shrink-0 rounded-lg bg-emerald-600 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-emerald-500">
                        {{ __('New deposit') }}
                    </a>
                </div>
            </div>

            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 lg:col-span-2">
                <div class="grid grid-cols-4 divide-x divide-gray-100 dark:divide-gray-700">
                    @foreach ([
                        ['label' => __('Total'), 'value' => $d['total'], 'accent' => 'sky'],
                        ['label' => __('Pending'), 'value' => $d['pending'], 'accent' => 'amber'],
                        ['label' => __('Accepted'), 'value' => $d['accepted'], 'accent' => 'emerald'],
                        ['label' => __('Rejected'), 'value' => $d['rejected'], 'accent' => 'rose'],
                    ] as $card)
                        <div class="ff-app-insights-kpi ff-member-stat-card px-3 py-2.5 text-center" data-accent="{{ $card['accent'] }}">
                            <p class="text-lg font-bold tabular-nums text-gray-900 dark:text-white">{{ $card['value'] }}</p>
                            <p class="text-[10px] font-medium uppercase tracking-wide text-gray-500">{{ $card['label'] }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="border-t border-gray-100 px-3 py-2 dark:border-gray-700">
                    <p class="mb-1 text-[10px] font-medium uppercase tracking-wide text-gray-400">{{ __('Last 6 months') }}</p>
                    <div class="flex h-8 items-end gap-1">
                        @foreach ($d['sparkline'] as $point)
                            <div class="flex-1 rounded-t bg-emerald-500/80 dark:bg-emerald-400/70"
                                style="height: {{ max(8, (int) round(($point / $sparkMax) * 100)) }}%"></div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        @if (count($d['recent']) > 0)
            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Recent deposits') }}
                    </h3>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($d['recent'] as $row)
                        <li class="flex justify-between px-3 py-2 text-xs">
                            <span>{{ $row['date'] }} · {{ $row['status_label'] }}</span>
                            <span class="font-semibold tabular-nums">{{ $row['amount'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
