@php
    $d = $this->getData();
@endphp

@if (empty($d))
    <div
        class="rounded-xl border border-dashed border-gray-200 px-4 py-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {{ __('Loading account insights…') }}
    </div>
@else
    <div class="ff-app-insights ff-member-account-detail-insights w-full max-w-none space-y-2.5 mb-1">
        <div class="grid grid-cols-1 gap-2.5 lg:grid-cols-3">
            <div class="space-y-2 lg:col-span-1">
                @include('filament.member.widgets.partials.insights-hero', ['hero' => $d['hero']])
                <div
                    @class([
                        'ff-member-stat-card overflow-hidden rounded-xl border px-3 py-2 shadow-sm',
                        'border-gray-200/80 dark:border-gray-700' => ! ($d['balance_negative'] ?? false),
                        'border-rose-200/80 dark:border-rose-500/30' => $d['balance_negative'] ?? false,
                    ])
                    data-accent="{{ ($d['balance_negative'] ?? false) ? 'rose' : ($d['account']['type'] === 'cash' ? 'sky' : 'emerald') }}">
                    <div class="flex items-end justify-between gap-2">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ $d['account']['type_label'] }}</p>
                            <p @class([
                                'text-2xl font-bold tabular-nums leading-tight',
                                $d['balance_negative']
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : 'text-emerald-600 dark:text-emerald-400',
                            ])>{{ $d['balance_display'] }}</p>
                            <p class="text-[10px] text-gray-400">{{ $d['currency'] }} · {{ __('Current balance') }}</p>
                        </div>
                        <span
                            class="rounded-full bg-emerald-100 px-2 py-0.5 text-[9px] font-bold uppercase text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">
                            {{ match ($d['account']['type']) {
                                'cash' => __('Cash'),
                                'fund' => __('Fund'),
                                default => __('Acct'),
                            } }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2">
                @include('filament.member.widgets.partials.insights-kpi-strip', [
                    'kpis' => $d['kpis'],
                    'sparkline' => $d['sparkline'],
                    'sparklineMax' => $d['sparkline_max'],
                ])
            </div>
        </div>

        <div class="grid grid-cols-1 gap-2.5 md:grid-cols-2">
            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-1.5 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-clock class="h-3.5 w-3.5 text-indigo-500" />
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('Recent ledger') }}</h3>
                    </div>
                    <span class="text-[10px] text-gray-400">{{ __('Last 5') }}</span>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($d['recent'] as $tx)
                        <li class="flex items-start justify-between gap-2 px-3 py-1.5 text-xs">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-800 dark:text-gray-200">{{ $tx['description'] }}</p>
                                <p class="text-[10px] text-gray-400">
                                    {{ $tx['transacted_at'] }}
                                    @if ($tx['is_reversal'])
                                        · {{ __('Reversal') }}
                                    @endif
                                </p>
                            </div>
                            <span @class(['shrink-0 font-semibold tabular-nums', $tx['signed_class']])>
                                {{ $tx['type'] === 'credit' ? '+' : '−' }}{{ $tx['amount'] }}
                            </span>
                        </li>
                    @empty
                        <li class="px-3 py-4 text-center text-[11px] text-gray-400">{{ __('No transactions yet') }}</li>
                    @endforelse
                </ul>
            </div>

            <div class="space-y-2.5">
                @foreach ($d['context']['panels'] ?? [] as $panel)
                    <div
                        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="border-b border-gray-100 px-3 py-1.5 dark:border-gray-700">
                            <h3 class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ $panel['title'] }}</h3>
                        </div>
                        <div class="space-y-1.5 px-3 py-2">
                            @foreach ($panel['rows'] as $row)
                                <div class="flex items-center justify-between gap-2 text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">{{ $row['label'] }}</span>
                                    <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $row['value'] }}</span>
                                </div>
                            @endforeach
                            @if (!empty($panel['progress']))
                                <div class="pt-1">
                                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                        <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500"
                                            style="width: {{ min(100, (float) $panel['progress']) }}%"></div>
                                    </div>
                                </div>
                            @endif
                            @if (!empty($panel['url']))
                                <a href="{{ $panel['url'] }}"
                                    class="inline-block text-[10px] font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                    {{ $panel['link_label'] }} →
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
