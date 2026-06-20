@php
    $d = $this->getData();
    $pollingInterval = method_exists($this, 'getPollingInterval') ? $this->getPollingInterval() : null;
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1" @if (filled($pollingInterval)) wire:poll.{{ $pollingInterval }} @endif>
    @if (empty($d))
        <div
            class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            {{ __('Loading member insights…') }}
        </div>
    @else
        <x-member-lifecycle-stepper :steps="$d['steps']" />

        <div class="ff-app-insights-head space-y-3">
            @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $d['hero']])

            <div class="grid max-w-lg grid-cols-2 gap-2 sm:max-w-xl">
                <a href="{{ $d['balances']['cash']['url'] ?? '#' }}"
                        @class([
                            'relative block overflow-hidden rounded-xl border border-gray-200 bg-white px-3 py-2.5 shadow-sm transition hover:bg-gray-50/80 dark:border-white/10 dark:bg-slate-800',
                            'pointer-events-none opacity-60' => empty($d['balances']['cash']['url']),
                        ])>
                        <div @class([
                            'absolute inset-y-0 left-0 w-0.5',
                            $d['balances']['cash']['negative'] ? 'bg-rose-500' : 'bg-emerald-500',
                        ])></div>
                        <p class="pl-1 text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Cash') }}</p>
                        <p @class([
                            'pl-1 text-lg font-bold tabular-nums leading-tight',
                            $d['balances']['cash']['negative']
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-emerald-600 dark:text-emerald-400',
                        ])><x-member::amount :value="$d['balances']['cash']['amount']" :currency="$d['currency']" /></p>
                    </a>
                    <a href="{{ $d['balances']['fund']['url'] ?? '#' }}"
                        @class([
                            'relative block overflow-hidden rounded-xl border border-gray-200 bg-white px-3 py-2.5 shadow-sm transition hover:bg-gray-50/80 dark:border-white/10 dark:bg-slate-800',
                            'pointer-events-none opacity-60' => empty($d['balances']['fund']['url']),
                        ])>
                        <div @class([
                            'absolute inset-y-0 left-0 w-0.5',
                            $d['balances']['fund']['negative'] ? 'bg-rose-500' : 'bg-indigo-500',
                        ])></div>
                        <p class="pl-1 text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Fund') }}</p>
                        <p @class([
                            'pl-1 text-lg font-bold tabular-nums leading-tight',
                            $d['balances']['fund']['negative']
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-indigo-600 dark:text-indigo-400',
                        ])><x-member::amount :value="$d['balances']['fund']['amount']" :currency="$d['currency']" /></p>
                    </a>
            </div>

            @include('filament.tenant.widgets.partials.insights-kpi-strip', [
                'kpis' => $d['kpis'],
                'sparkline' => $d['sparkline'],
                'sparklineMax' => $d['sparkline_max'],
            ])
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div
                    class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-arrow-path class="h-4 w-4 text-sky-500" />
                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ __('Open cycle') }}</h3>
                    </div>
                    <span @class([
                        'rounded px-1.5 py-0.5 text-[9px] font-bold uppercase',
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $d['cycle']['status_key'] === 'posted',
                        'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-200' => $d['cycle']['status_key'] === 'loan_repayment',
                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => in_array($d['cycle']['status_key'], ['exempt', 'short'], true),
                        'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200' => in_array($d['cycle']['status_key'], ['ready', 'waiting'], true),
                        'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => $d['cycle']['status_key'] === 'na',
                    ])>{{ $d['cycle']['status_label'] }}</span>
                </div>
                <div class="space-y-2 px-3 py-2.5 text-xs">
                    <div class="flex justify-between gap-2">
                        <span class="text-gray-500">{{ __('Period') }}</span>
                        <span class="font-semibold text-gray-900 dark:text-white">{{ $d['cycle']['period_label'] }}</span>
                    </div>
                    @if ($d['cycle']['under_loan_repayment'] ?? false)
                        <p class="rounded-lg bg-violet-50 px-2 py-1.5 text-sm font-semibold text-violet-900 dark:bg-violet-950/30 dark:text-violet-100">
                            {{ $d['cycle']['loan_repayment_message'] ?? __('Under loan repayment') }}
                        </p>
                    @else
                        <div class="flex justify-between gap-2">
                            <span class="text-gray-500">{{ __('Required cash') }}</span>
                            <span class="font-semibold tabular-nums"><x-member::amount :value="$d['cycle']['required_cash']" :currency="$d['currency']" /></span>
                        </div>
                    @endif
                    @if ($d['fund_summary']['fund_minimum_pct'] !== null)
                        <div>
                            <div class="mb-0.5 flex justify-between text-[10px] text-gray-500">
                                <span>{{ __('Fund vs monthly') }}</span>
                                <span class="font-semibold">{{ $d['fund_summary']['fund_minimum_pct'] }}%</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500"
                                    style="width: {{ min(100, $d['fund_summary']['fund_minimum_pct']) }}%"></div>
                            </div>
                        </div>
                    @endif
                    <a href="{{ $d['cycle']['cycle_url'] }}"
                        class="inline-block text-[10px] font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                        {{ __('Open contribution cycle') }} →
                    </a>
                </div>
            </div>

            @if ($d['loan'])
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-slate-800">
                    <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-white/10">
                        <div class="flex items-center gap-1.5">
                            <x-heroicon-o-currency-dollar class="h-4 w-4 text-violet-500" />
                            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ __('Active loan') }}</h3>
                        </div>
                        <span class="text-[10px] font-medium text-gray-400">{{ $d['loan']['status_label'] }}</span>
                    </div>
                    <div class="space-y-2 px-3 py-2.5">
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-600 dark:text-gray-300">{{ __('Outstanding') }}</span>
                            <span class="font-bold tabular-nums text-gray-900 dark:text-white"><x-member::amount :value="$d['loan']['outstanding']" :currency="$d['currency']" /></span>
                        </div>
                        <div>
                            <div class="mb-0.5 flex justify-between text-[10px] text-gray-500">
                                <span>{{ __('Repayment schedule') }}</span>
                                <span>{{ $d['loan']['installments_paid'] }}/{{ $d['loan']['installments_total'] }}
                                    · {{ $d['loan']['repay_percent'] }}%</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-white/60 dark:bg-gray-800/60">
                                <div class="h-full rounded-full bg-gradient-to-r from-violet-500 to-indigo-500"
                                    style="width: {{ max(4, $d['loan']['repay_percent']) }}%"></div>
                            </div>
                        </div>
                        @if ($d['loan']['overdue_count'] > 0)
                            <p class="text-[10px] font-semibold text-rose-600 dark:text-rose-400">
                                {{ trans_choice(':count overdue installment|:count overdue installments', $d['loan']['overdue_count'], ['count' => $d['loan']['overdue_count']]) }}
                            </p>
                        @endif
                        <a href="{{ $d['loan']['edit_url'] }}"
                            class="inline-block text-[10px] font-medium text-violet-700 hover:underline dark:text-violet-300">
                            {{ __('Manage loan') }} →
                        </a>
                    </div>
                </div>
            @else
                <div
                    class="overflow-hidden rounded-xl border border-gray-200/80 bg-white px-3 py-2.5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-sparkles class="h-4 w-4 text-teal-500" />
                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ __('Loan eligibility') }}</h3>
                    </div>
                    <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">
                        {{ $d['eligibility']['eligible'] ? __('Eligible to apply') : __('Not eligible') }}
                    </p>
                    @if (! $d['eligibility']['eligible'] && filled($d['eligibility']['reason'] ?? null))
                        <p class="mt-1 text-[11px] text-gray-500">{{ $d['eligibility']['reason'] }}</p>
                    @endif
                    <p class="mt-2 text-[10px] text-gray-400">
                        {{ __('Total posted') }}: <x-member::amount :value="$d['fund_summary']['contributions_total']" :currency="$d['currency']" />
                        · {{ $d['fund_summary']['contributions_count'] }} {{ __('payments') }}
                    </p>
                </div>
            @endif
        </div>

        @if ($d['arrears']['visible'])
            <div class="relative overflow-hidden rounded-xl border border-rose-200 bg-white px-3 py-2.5 shadow-sm dark:border-rose-500/25 dark:bg-slate-800">
                <div class="absolute inset-y-0 left-0 w-0.5 bg-rose-500"></div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-exclamation-triangle class="h-4 w-4 text-rose-600" />
                        <div>
                            <p class="text-xs font-semibold text-rose-900 dark:text-rose-100">{{ __('Arrears summary') }}
                            </p>
                            <p class="text-[11px] text-gray-600 dark:text-gray-400">
                                @if ($d['arrears']['overdue_installments'] > 0)
                                    {{ trans_choice(':count overdue installment|:count overdue installments', $d['arrears']['overdue_installments'], ['count' => $d['arrears']['overdue_installments']]) }}
                                @endif
                                @if (count($d['arrears']['unpaid_periods']) > 0)
                                    · {{ implode(', ', $d['arrears']['unpaid_periods']) }}
                                @endif
                            </p>
                        </div>
                    </div>
                    <a href="{{ $d['arrears']['cta_url'] }}"
                        class="ff-tenant-btn ff-tenant-btn--danger shrink-0 px-2.5 py-1 text-[11px]">
                        {{ $d['arrears']['cta_label'] }}
                    </a>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($d['relation_summaries'] as $card)
                @php
                    $accentBar = [
                        'teal' => 'bg-teal-500',
                        'indigo' => 'bg-indigo-500',
                        'violet' => 'bg-violet-500',
                        'emerald' => 'bg-emerald-500',
                        'sky' => 'bg-sky-500',
                    ];
                    $bar = $accentBar[$card['accent']] ?? 'bg-gray-400';
                @endphp
                <a href="{{ $card['url'] ?? '#' }}"
                    @class([
                        'relative block overflow-hidden rounded-xl border border-gray-200/80 bg-white px-3 py-2.5 shadow-sm transition hover:bg-gray-50/80 dark:border-gray-700 dark:bg-gray-800 dark:hover:bg-gray-800/60',
                        'pointer-events-none' => empty($card['url']),
                    ])>
                    <div class="absolute inset-y-0 left-0 w-0.5 {{ $bar }}"></div>
                    <div class="flex items-center gap-1.5 pl-1">
                        <x-dynamic-component :component="$card['icon']" class="h-3.5 w-3.5 text-gray-400" />
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ ui_label($card['label']) }}</p>
                    </div>
                    <p class="mt-1 pl-1 text-sm font-bold text-gray-900 dark:text-white">
                        @if (($card['key'] ?? null) === 'accounts' && isset($card['value_amount']))
                            <x-member::amount :value="$card['value_amount']" :currency="$d['currency']" /> · {{ __('Cash') }}
                        @elseif (($card['key'] ?? null) === 'loans' && isset($card['value_amount']))
                            {{ __('Active') }} · <x-member::amount :value="$card['value_amount']" :currency="$d['currency']" />
                        @else
                            {{ $card['value'] }}
                        @endif
                    </p>
                    @if (filled($card['hint'] ?? null))
                        <p class="pl-1 text-[10px] text-gray-400">
                            @if (($card['key'] ?? null) === 'accounts' && isset($card['hint_amount']))
                                <x-member::amount :value="$card['hint_amount']" :currency="$d['currency']" /> {{ __('fund') }}
                            @else
                                {{ $card['hint'] }}
                            @endif
                        </p>
                    @endif
                </a>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            @include('filament.partials.insights.six-month-dual-progress-panel', [
                'title' => __('6-month contributions'),
                'trend' => $d['trend'],
                'primaryLabel' => __('Posted'),
                'secondaryLabel' => __('Amount'),
            ])

            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div
                    class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-clock class="h-4 w-4 text-sky-500" />
                        <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ __('Recent activity') }}</h4>
                    </div>
                    <div class="flex gap-2">
                        @foreach ($d['quick_links'] as $link)
                            <a href="{{ $link['url'] }}" title="{{ $link['label'] }}"
                                class="text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400">
                                <x-dynamic-component :component="$link['icon']" class="h-3.5 w-3.5" />
                            </a>
                        @endforeach
                    </div>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($d['recent_activity'] as $tx)
                        <li class="flex items-start justify-between gap-2 px-3 py-2 text-xs">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-800 dark:text-gray-200">{{ $tx['description'] }}</p>
                                <p class="text-[10px] text-gray-400">{{ $tx['transacted_at'] }}</p>
                            </div>
                            <span @class(['shrink-0 font-semibold tabular-nums', $tx['signed_class']])>
                                <x-member::amount :value="$tx['amount']" :currency="$d['currency']" />
                            </span>
                        </li>
                    @empty
                        <li class="px-3 py-4 text-center text-[11px] text-gray-400">{{ __('No ledger activity yet') }}
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>

        @if (count($d['household']['dependents']) > 0 || filled($d['member']['parent_url']))
            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Household') }}
                    </h4>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @if (filled($d['member']['parent_url']))
                        <a href="{{ $d['member']['parent_url'] }}"
                            class="flex items-center gap-2 px-3 py-2 text-xs transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                            <x-heroicon-o-user class="h-4 w-4 text-sky-500" />
                            <span>{{ __('Parent') }}: <strong>{{ $d['member']['parent_name'] }}</strong></span>
                        </a>
                    @endif
                    @foreach ($d['household']['dependents'] as $dependent)
                        <a href="{{ $dependent['edit_url'] }}"
                            class="flex items-center justify-between gap-2 px-3 py-2 text-xs transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                            <span class="font-medium text-gray-900 dark:text-white">{{ $dependent['name'] }}</span>
                            <span class="text-[10px] text-gray-400">{{ $dependent['number'] }} ·
                                {{ $dependent['status'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
