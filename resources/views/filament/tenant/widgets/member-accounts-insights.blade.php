@php
    $d = $this->getData();
    $pollingInterval = method_exists($this, 'getPollingInterval') ? $this->getPollingInterval() : null;
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1" @if (filled($pollingInterval)) wire:poll.{{ $pollingInterval }} @endif>
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
        @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $d['hero']])
        @include('filament.tenant.widgets.partials.insights-kpi-strip', ['kpis' => $d['kpis']])
    </div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-funnel class="h-4 w-4 text-sky-500" />
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Pipeline') }}
                    </h3>
                </div>
            </div>
            <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700">
                <a href="{{ $d['urls']['members'] }}"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $d['active_members'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Active') }}</span>
                </a>
                <a href="{{ $d['urls']['loans'] }}"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-amber-50/70 dark:hover:bg-amber-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $d['active_loan_count'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Loans') }}</span>
                </a>
                <a href="{{ $d['urls']['contributions'] }}"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-emerald-50/70 dark:hover:bg-emerald-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $d['pending_contributions'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Pending') }}</span>
                </a>
            </div>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-chart-bar class="h-4 w-4 text-indigo-500" />
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('6-month ledger volume') }}
                    </h4>
                </div>
                <span class="text-[10px] text-gray-400">{{ $d['active_tab_label'] }}</span>
            </div>
            <div class="px-3 py-3">
                <div class="flex h-20 items-end gap-1.5">
                    @foreach ($d['trend'] as $month)
                        @php
                            $barH = max(12, (int) round(($month['total'] / $d['max_trend']) * 100));
                            $creditH = $month['total'] > 0 ? round(($month['credits'] / $month['total']) * 100) : 0;
                        @endphp
                        <div class="flex flex-1 flex-col items-center gap-0.5">
                            <span
                                class="text-[10px] font-semibold tabular-nums text-gray-500">{{ $month['total'] > 0 ? \App\Support\Insights\InsightFormatter::compactAmount($month['total']) : '·' }}</span>
                            <div class="flex w-full max-w-[2.25rem] flex-col justify-end overflow-hidden rounded-t-md ring-1 ring-gray-200/60 dark:ring-gray-600"
                                style="height: {{ $barH }}%">
                                @if ($month['credits'] > 0)
                                    <div class="w-full bg-emerald-500" style="height: {{ max(3, $creditH) }}%"></div>
                                @endif
                                @if ($month['debits'] > 0)
                                    <div class="w-full bg-rose-400" style="height: {{ max(3, 100 - $creditH) }}%"></div>
                                @endif
                            </div>
                            <span class="text-[10px] text-gray-400">{{ $month['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div
        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-user-group class="h-4 w-4 text-amber-500" />
                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('At-risk members') }}
                </h4>
            </div>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @forelse ($d['at_risk_members'] as $member)
                <a href="{{ $member['url'] }}"
                    class="flex items-center gap-2 px-3 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-[10px] font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                        {{ strtoupper(substr($member['name'], 0, 1)) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-medium text-gray-900 dark:text-white">
                            <x-arabic-text :text="$member['name']" />
                        </p>
                        <p class="text-[10px] text-gray-400">
                            {{ __('Cash') }} {{ \App\Support\Insights\InsightFormatter::money($member['cash']) }} ·
                            {{ __('Fund') }} {{ \App\Support\Insights\InsightFormatter::money($member['fund']) }}
                        </p>
                    </div>
                </a>
            @empty
                <div class="px-3 py-6 text-center">
                    <x-heroicon-o-check-circle class="mx-auto h-6 w-6 text-emerald-400" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('No at-risk members') }}</p>
                </div>
            @endforelse
        </div>
    </div>
</div>