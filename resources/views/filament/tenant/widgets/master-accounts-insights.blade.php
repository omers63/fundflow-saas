@php
    $d = $this->getData();
    $pollingInterval = method_exists($this, 'getPollingInterval') ? $this->getPollingInterval() : null;
    $masterFundBalance = (float) ($d['master_fund'] ?? 0);
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1" @if (filled($pollingInterval)) wire:poll.{{ $pollingInterval }} @endif>
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
        @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $d['hero']])
        @include('filament.tenant.widgets.partials.insights-kpi-strip', [
            'kpis' => $d['kpis'],
            'sparkline' => $d['sparkline'],
            'sparklineMax' => $d['sparkline_max'],
        ])
    </div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-shield-check class="h-4 w-4 text-emerald-500" />
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Fund health') }}
                    </h3>
                </div>
                @php
                    $healthBadge = match ($d['fund_health']) {
                        'healthy' => [__('Healthy'), 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'],
                        'monitor' => [__('Monitor'), 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'],
                        default => [__('Action'), 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200'],
                    };
                @endphp
                <span @class(['rounded-full px-2 py-0.5 text-[9px] font-bold uppercase', $healthBadge[1]])>
                    {{ $healthBadge[0] }}
                </span>
            </div>
            <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700">
                <div class="px-3 py-2.5 text-center">
                    <p class="text-[10px] text-gray-500">{{ __('Master fund') }}</p>
                    <p class="text-sm font-bold tabular-nums text-emerald-600 dark:text-emerald-400">
                        {{ \App\Support\Insights\InsightFormatter::compactAmount($masterFundBalance) }}
                    </p>
                </div>
                <div class="px-3 py-2.5 text-center">
                    <p class="text-[10px] text-gray-500">{{ __('Loan exposure') }}</p>
                    <p class="text-sm font-bold tabular-nums text-amber-600 dark:text-amber-400">
                        {{ \App\Support\Insights\InsightFormatter::compactAmount($d['loan_exposure']) }}
                    </p>
                </div>
                <div class="px-3 py-2.5 text-center">
                    <p class="text-[10px] text-gray-500">{{ __('Coverage') }}</p>
                    <p class="text-sm font-bold tabular-nums text-gray-900 dark:text-white">
                        {{ $d['coverage'] !== null ? $d['coverage'].'×' : '—' }}
                    </p>
                </div>
            </div>
            <div class="px-3 pb-3">
                <div class="mb-1 flex justify-between text-[10px] text-gray-500">
                    <span>{{ __('Fund vs loans') }}</span>
                    <span>{{ $d['coverage_percent'] }}%</span>
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                    <div class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-500"
                        style="width: {{ min(100, $d['coverage_percent']) }}%"></div>
                </div>
            </div>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="grid grid-cols-1 divide-y divide-gray-100 dark:divide-gray-700 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                <div class="px-3 py-2.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Member pool') }}
                    </p>
                    <div class="mt-2 space-y-2">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-600 dark:text-gray-300">{{ __('Cash') }}</span>
                            <span
                                class="font-semibold tabular-nums">{{ \App\Support\Insights\InsightFormatter::money($d['member_cash_total']) }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-600 dark:text-gray-300">{{ __('Fund') }}</span>
                            <span
                                class="font-semibold tabular-nums">{{ \App\Support\Insights\InsightFormatter::money($d['member_fund_total']) }}</span>
                        </div>
                    </div>
                </div>
                <div class="px-3 py-2.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('30-day flow') }}
                    </p>
                    <p class="mt-1 text-lg font-bold tabular-nums text-gray-900 dark:text-white">
                        {{ ($d['activity_net'] >= 0 ? '+' : '−') . \App\Support\Insights\InsightFormatter::compactAmount($d['activity_net']) }}
                        <span class="text-[10px] font-normal text-gray-400">{{ $d['currency'] }}</span>
                    </p>
                    <p class="text-[10px] text-gray-400">
                        {{ \App\Support\Insights\InsightFormatter::money($d['activity_credits']) }}
                        {{ __('in') }} ·
                        {{ \App\Support\Insights\InsightFormatter::money($d['activity_debits']) }}
                        {{ __('out') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

</div>
