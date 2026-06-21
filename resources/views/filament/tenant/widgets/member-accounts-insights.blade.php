@php
    $d = $this->getData();
    $pollingInterval = method_exists($this, 'getPollingInterval') ? $this->getPollingInterval() : null;
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1" @if (filled($pollingInterval)) wire:poll.{{ $pollingInterval }} @endif>
    @include('filament.tenant.widgets.partials.insights-head', [
        'hero' => $d['hero'],
        'kpis' => $d['kpis'],
    ])

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

        @include('filament.partials.insights.six-month-volume-panel', [
            'title' => __('6-month ledger volume'),
            'trend' => $d['trend'],
        ])
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
                            {{ __('Cash') }} {!! \App\Support\Insights\InsightFormatter::moneyMarkup($member['cash']) !!} ·
                            {{ __('Fund') }} {!! \App\Support\Insights\InsightFormatter::moneyMarkup($member['fund']) !!}
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