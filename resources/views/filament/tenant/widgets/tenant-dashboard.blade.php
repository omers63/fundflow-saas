@php
    $d = $this->getData();
    $breakdown = $d['collection_breakdown'];
    $loanQueue = $d['loan_queue_preview'];
    $activity = $d['recent_activity'];
    $pipeline = $d['loan_pipeline'];
    $loanPortfolio = $d['loan_portfolio'];
    $lifetime = $d['lifetime_fund_activity'];
    $forecast = $d['forecast_summary'];
    $greeting = $d['greeting'];
    $pool = $d['pool_health'];
@endphp
    
    <div class="w-full max-w-none space-y-3 pb-6">
    
        {{-- ── Fund overview hero ── --}}
        <div
            class="ff-tenant-dashboard-hero overflow-hidden rounded-2xl border border-sky-200/80 bg-gradient-to-br from-sky-50 via-white to-emerald-50/50 shadow-md dark:border-sky-800/40 dark:from-sky-950/50 dark:via-gray-900 dark:to-emerald-950/25">
            <div class="px-5 py-5 sm:px-6 sm:py-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="flex min-w-0 items-start gap-4">
                        <div
                            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-sky-200/80 bg-white shadow-sm dark:border-sky-700/50 dark:bg-sky-950/60">
                            <x-heroicon-o-building-library class="h-7 w-7 text-sky-600 dark:text-sky-400" />
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wider text-sky-600 dark:text-sky-400">
                                {{ $greeting['period_label'] }}, {{ $greeting['name'] }}
                            </p>
                            <h2 class="mt-1 truncate text-xl font-bold text-gray-900 dark:text-white sm:text-2xl">
                                {{ $greeting['fund_name'] }}
                            </h2>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $greeting['date'] }}</p>
                            <p class="mt-2 max-w-2xl text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                                {{ $greeting['subtitle'] }}
                            </p>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2 lg:justify-end">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-lg bg-sky-100/90 px-3 py-1.5 text-xs font-semibold text-sky-800 ring-1 ring-inset ring-sky-200/80 dark:bg-sky-900/50 dark:text-sky-200 dark:ring-sky-700/50">
                            <x-heroicon-o-calendar-days class="h-4 w-4 shrink-0" />
                            {{ __('Cycle: :label', ['label' => $d['open_period_label']]) }}
                        </span>
                    </div>
                </div>
    
                <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    @foreach ($d['balances'] as $balance)
                        <a href="{{ $balance['url'] }}"
                            class="ff-tenant-dashboard-hero__balance group relative overflow-hidden rounded-xl border border-white/90 bg-white/95 p-4 shadow-sm ring-1 ring-gray-200/70 transition hover:-translate-y-0.5 hover:shadow-md dark:border-white/5 dark:bg-gray-900/70 dark:ring-white/10">
                            <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r {{ $balance['gradient'] }}"></div>
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gray-50 text-gray-600 ring-1 ring-gray-100 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/5">
                                    <x-dynamic-component :component="$balance['icon']" class="h-5 w-5" />
                                </div>
                                <div class="min-w-0">
                                    <p
                                        class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ $balance['label'] }}
                                    </p>
                                    <p
                                        class="mt-0.5 truncate text-lg font-bold leading-tight text-gray-900 dark:text-white sm:text-xl">
                                        <x-member::amount :value="$balance['amount']" />
                                    </p>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    
        {{-- ── 4 KPI stat cards ── --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ($d['kpi_stats'] as $stat)
                @php
                    $subColor = match ($stat['sub_tone'] ?? '') {
                        'success', 'emerald' => 'text-emerald-600 dark:text-emerald-400',
                        'amber', 'warning' => 'text-amber-600 dark:text-amber-400',
                        'danger', 'rose' => 'text-red-600 dark:text-red-400',
                        default => 'text-gray-400',
                    };
                @endphp
                <a href="{{ $stat['url'] }}"
                    class="ff-tenant-dashboard-kpi group flex min-w-0 flex-col gap-1 overflow-hidden rounded-xl border border-gray-200 bg-white px-4 py-3.5 shadow-sm transition hover:-translate-y-0.5 hover:border-sky-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-900">
                    <p class="truncate text-[10px] font-semibold uppercase tracking-wide text-gray-400"
                        title="{{ $stat['label'] }}">{{ $stat['label'] }}</p>
                    <p class="truncate text-[26px] font-bold tabular-nums leading-none text-gray-900 dark:text-white"
                        title="{{ $stat['value'] }}">{{ $stat['value'] }}</p>
                    <p class="{{ $subColor }} truncate text-[11px] font-medium" title="{{ $stat['sub'] }}">{{ $stat['sub'] }}
                    </p>
                </a>
            @endforeach
        </div>
    
        <div
            class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div
                class="flex flex-col gap-2 border-b border-gray-100 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-700">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-sparkles class="h-4 w-4 text-violet-500" />
                    <span
                        class="text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Forecast summary') }}</span>
                </div>
                <span @class([
                    'inline-flex items-center rounded-lg px-2.5 py-1 text-[10px] font-semibold ring-1 ring-inset',
                    'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/30 dark:text-red-300 dark:ring-red-800/40' => ($forecast['top_risk']['tone'] ?? '') === 'danger',
                    'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-300 dark:ring-amber-800/40' => ($forecast['top_risk']['tone'] ?? '') === 'warning',
                    'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-300 dark:ring-emerald-800/40' => !in_array(($forecast['top_risk']['tone'] ?? ''), ['danger', 'warning'], true),
                ])>
                {{ $forecast['top_risk']['label'] }} · {{ $forecast['top_risk']['secondary'] }}
                </span>
            </div>
            <div class="grid grid-cols-1 gap-3 p-4 lg:grid-cols-3">
                @foreach ($forecast['cards'] as $card)
                    <a href="{{ $card['url'] }}"
                        class="group rounded-xl border border-gray-200/80 bg-gray-50/70 px-3 py-3 transition hover:-translate-y-0.5 hover:border-sky-200 hover:bg-white hover:shadow-sm dark:border-gray-700 dark:bg-gray-800/70 dark:hover:border-sky-800/50 dark:hover:bg-gray-800">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $card['label'] }}</p>
                            <span @class([
                                'rounded-full px-2 py-0.5 text-[9px] font-semibold uppercase',
                                'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300' => ($card['tone'] ?? '') === 'danger',
                                'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' => ($card['tone'] ?? '') === 'warning',
                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' => !in_array(($card['tone'] ?? ''), ['danger', 'warning'], true),
                            ])>{{ $card['secondary'] }}</span>
                        </div>
                        <p class="mt-2 text-lg font-bold tabular-nums text-gray-900 dark:text-white">{{ $card['primary'] }}</p>
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $card['detail'] }}</p>
                        <p class="mt-1 text-[11px] text-gray-400">{{ $card['meta'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>

    {{-- ── Active loan portfolio ── --}}
    <div
        class="ff-tenant-loan-portfolio rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div
            class="flex flex-col gap-2 border-b border-gray-100 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between dark:border-gray-700">
            <div class="flex min-w-0 items-center gap-2">
                <x-heroicon-o-banknotes class="h-4 w-4 shrink-0 text-emerald-500" />
                <span
                    class="text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Active loan portfolio') }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if (($loanPortfolio['queue_count'] ?? 0) > 0)
                    <a href="{{ $loanPortfolio['queue_url'] }}"
                        class="text-xs font-semibold text-amber-600 hover:underline dark:text-amber-400">
                        {{ trans_choice(':count in queue →|:count in queue →', $loanPortfolio['queue_count'], ['count' => $loanPortfolio['queue_count']]) }}
                    </a>
                @endif
                <a href="{{ $loanPortfolio['loans_url'] }}"
                    class="text-xs font-semibold text-sky-600 hover:underline dark:text-sky-400">{{ __('View loans →') }}</a>
            </div>
        </div>
        <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-3">
            <a href="{{ $loanPortfolio['active_loans_url'] }}"
                class="ff-tenant-loan-portfolio__stat group min-w-0 rounded-lg border border-transparent px-3 py-2.5 transition hover:border-emerald-200 hover:bg-emerald-50/60 dark:hover:border-emerald-900/40 dark:hover:bg-emerald-950/20">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('Active loans') }}</p>
                <p
                    class="mt-0.5 text-2xl font-bold tabular-nums leading-none text-gray-900 group-hover:text-emerald-700 dark:text-white dark:group-hover:text-emerald-300">
                    {{ number_format($loanPortfolio['active_count']) }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Currently repaying') }}</p>
            </a>
            <a href="{{ $loanPortfolio['active_loans_url'] }}"
                class="ff-tenant-loan-portfolio__stat group min-w-0 rounded-lg border border-transparent px-3 py-2.5 transition hover:border-sky-200 hover:bg-sky-50/60 dark:hover:border-sky-900/40 dark:hover:bg-sky-950/20">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('Portfolio value') }}</p>
                <p
                    class="mt-0.5 break-words text-xl font-bold tabular-nums leading-tight text-gray-900 group-hover:text-sky-700 dark:text-white dark:group-hover:text-sky-300">
                    {!! \App\Support\Insights\InsightFormatter::moneyMarkup($loanPortfolio['active_amount_total']) !!}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Total approved on active loans') }}</p>
            </a>
            <a href="{{ ($loanPortfolio['overdue_installments'] ?? 0) > 0 ? $loanPortfolio['overdue_url'] : $loanPortfolio['outstanding_url'] }}"
                class="ff-tenant-loan-portfolio__stat group min-w-0 rounded-lg border border-transparent px-3 py-2.5 transition hover:border-violet-200 hover:bg-violet-50/60 dark:hover:border-violet-900/40 dark:hover:bg-violet-950/20">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('Outstanding') }}</p>
                <p @class([
    'mt-0.5 break-words text-xl font-bold tabular-nums leading-tight',
    'text-red-600 group-hover:text-red-700 dark:text-red-400 dark:group-hover:text-red-300' => ($loanPortfolio['overdue_installments'] ?? 0) > 0,
    'text-gray-900 group-hover:text-violet-700 dark:text-white dark:group-hover:text-violet-300' => ($loanPortfolio['overdue_installments'] ?? 0) === 0,
])>
                    {!! \App\Support\Insights\InsightFormatter::moneyMarkup($loanPortfolio['outstanding_total']) !!}
                </p>
                <p @class([
    'mt-1 text-xs',
    'text-red-600 dark:text-red-400' => ($loanPortfolio['overdue_installments'] ?? 0) > 0,
    'text-gray-500 dark:text-gray-400' => ($loanPortfolio['overdue_installments'] ?? 0) === 0,
])>
                    @if (($loanPortfolio['overdue_installments'] ?? 0) > 0)
                        {{ trans_choice(':count overdue installment|:count overdue installments', $loanPortfolio['overdue_installments'], ['count' => $loanPortfolio['overdue_installments']]) }}
                    @else
                        {{ __('Pending and overdue EMIs') }}
                    @endif
                </p>
            </a>
        </div>
    </div>

    {{-- ── Lifetime fund activity ── --}}
    <div
        class="ff-tenant-lifetime-activity rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div
            class="flex flex-col gap-2 border-b border-gray-100 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between dark:border-gray-700">
            <div class="flex min-w-0 items-center gap-2">
                <x-heroicon-o-chart-bar class="h-4 w-4 shrink-0 text-indigo-500" />
                <span
                    class="text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Lifetime fund activity') }}</span>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('All-time disbursed loans and member collections') }}
            </p>
        </div>
        <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4">
            <a href="{{ $lifetime['loans_url'] }}"
                class="ff-tenant-lifetime-activity__stat group min-w-0 rounded-lg border border-transparent px-3 py-2.5 transition hover:border-indigo-200 hover:bg-indigo-50/60 dark:hover:border-indigo-900/40 dark:hover:bg-indigo-950/20">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('Loans issued') }}</p>
                <p
                    class="mt-0.5 text-2xl font-bold tabular-nums leading-none text-gray-900 group-hover:text-indigo-700 dark:text-white dark:group-hover:text-indigo-300">
                    {{ number_format($lifetime['loan_count']) }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Disbursed to members') }}</p>
            </a>
            <a href="{{ $lifetime['loans_url'] }}"
                class="ff-tenant-lifetime-activity__stat group min-w-0 rounded-lg border border-transparent px-3 py-2.5 transition hover:border-violet-200 hover:bg-violet-50/60 dark:hover:border-violet-900/40 dark:hover:bg-violet-950/20">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('Loan volume') }}</p>
                <p
                    class="mt-0.5 break-words text-xl font-bold tabular-nums leading-tight text-gray-900 group-hover:text-violet-700 dark:text-white dark:group-hover:text-violet-300">
                    {!! \App\Support\Insights\InsightFormatter::moneyMarkup($lifetime['loan_amount_total']) !!}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Total disbursed principal') }}</p>
            </a>
            <a href="{{ $lifetime['contributions_url'] }}"
                class="ff-tenant-lifetime-activity__stat group min-w-0 rounded-lg border border-transparent px-3 py-2.5 transition hover:border-emerald-200 hover:bg-emerald-50/60 dark:hover:border-emerald-900/40 dark:hover:bg-emerald-950/20">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('Contributions') }}</p>
                <p
                    class="mt-0.5 break-words text-xl font-bold tabular-nums leading-tight text-gray-900 group-hover:text-emerald-700 dark:text-white dark:group-hover:text-emerald-300">
                    {!! \App\Support\Insights\InsightFormatter::moneyMarkup($lifetime['contributions_total']) !!}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Posted contribution principal') }}</p>
            </a>
            <a href="{{ $lifetime['collections_url'] }}"
                class="ff-tenant-lifetime-activity__stat group min-w-0 rounded-lg border border-transparent px-3 py-2.5 transition hover:border-sky-200 hover:bg-sky-50/60 dark:hover:border-sky-900/40 dark:hover:bg-sky-950/20">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('Total collections') }}</p>
                <p
                    class="mt-0.5 break-words text-xl font-bold tabular-nums leading-tight text-gray-900 group-hover:text-sky-700 dark:text-white dark:group-hover:text-sky-300">
                    {!! \App\Support\Insights\InsightFormatter::moneyMarkup($lifetime['collections_total']) !!}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Contributions + repayments') }} ·
                    {!! \App\Support\Insights\InsightFormatter::moneyMarkup($lifetime['repayments_total']) !!}
                    {{ __('repaid') }}
                </p>
            </a>
        </div>
    </div>

    {{-- ── Fund pool health ── --}}
    <div @class([
    'ff-tenant-pool-health rounded-xl border bg-white shadow-sm dark:bg-gray-900',
    'border-red-300 dark:border-red-800/50' => $pool['has_drift'],
    'border-gray-200 dark:border-gray-700' => !$pool['has_drift'],
])>
        <div
            class="flex flex-col gap-2 border-b border-gray-100 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between dark:border-gray-700">
            <div class="flex min-w-0 items-center gap-2">
                <x-heroicon-o-beaker class="h-4 w-4 shrink-0 text-sky-500" />
                <span
                    class="text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Fund pool health') }}</span>
            </div>
            @if ($pool['has_drift'])
                <a href="{{ $pool['reconciliation_url'] }}"
                    class="shrink-0 text-xs font-semibold text-red-600 hover:underline sm:text-sm dark:text-red-400">{{ __('Review reconciliation →') }}</a>
            @endif
        </div>
        <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="min-w-0">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('Master cash') }}</p>
                <p class="mt-0.5 break-words text-sm font-bold tabular-nums text-gray-900 dark:text-white">{!! \App\Support\Insights\InsightFormatter::moneyMarkup($pool['master_cash']) !!}</p>
                <p class="mt-0.5 break-words text-xs text-gray-500">{{ __('Members') }}: {!! \App\Support\Insights\InsightFormatter::moneyMarkup($pool['member_cash']) !!}</p>
            </div>
            <div class="min-w-0">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('Master fund') }}</p>
                <p class="mt-0.5 break-words text-sm font-bold tabular-nums text-gray-900 dark:text-white">{!! \App\Support\Insights\InsightFormatter::moneyMarkup($pool['master_fund']) !!}</p>
                <p class="mt-0.5 break-words text-xs text-gray-500">{{ __('Members') }}: {!! \App\Support\Insights\InsightFormatter::moneyMarkup($pool['member_fund']) !!}</p>
            </div>
            <div class="min-w-0">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('Pool solvency') }}</p>
                <p class="mt-0.5 break-words text-sm font-bold tabular-nums text-gray-900 dark:text-white">
                    {{ $pool['solvency_ratio'] !== null ? $pool['solvency_ratio'] . '×' : '—' }}
                </p>
                <p class="mt-0.5 text-xs text-gray-500">{{ __('vs loan exposure') }}</p>
            </div>
            <div class="min-w-0">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('Pool drift') }}</p>
                <p @class([
    'ff-tenant-pool-health__status mt-0.5 text-base font-bold leading-snug break-words sm:text-lg',
    'text-red-600 dark:text-red-400' => $pool['has_drift'],
    'text-emerald-600 dark:text-emerald-400' => !$pool['has_drift'],
])>
                    {{ $pool['has_drift'] ? __('Variance detected') : __('Balanced') }}
                </p>
                <div class="mt-1 space-y-0.5 text-xs leading-snug text-gray-500 dark:text-gray-400">
                    <p class="break-words">{{ __('Cash drift') }}: {!! \App\Support\Insights\InsightFormatter::moneyMarkup($pool['cash_drift']) !!}</p>
                    <p class="break-words">{{ __('Fund drift') }}: {!! \App\Support\Insights\InsightFormatter::moneyMarkup($pool['fund_drift']) !!}</p>
                </div>
            </div>
        </div>
        @if (!empty($pool['sparkline']))
            <div class="border-t border-gray-100 px-4 py-3 dark:border-gray-700">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('30-day pool trend') }}
                    </p>
                    <p class="text-[10px] tabular-nums text-gray-500 dark:text-gray-400">
                        {{ \App\Support\Insights\InsightFormatter::compactAmount($pool['sparkline_start'] ?? 0) }}
                        →
                        {{ \App\Support\Insights\InsightFormatter::compactAmount($pool['sparkline_end'] ?? ($pool['pool_total'] ?? 0)) }}
                    </p>
                </div>
                <div class="flex h-8 items-end gap-px sm:h-10">
                    @php
    $sparklineMax = max(1, (float) ($pool['sparkline_max'] ?? 1));
                    @endphp
                    @foreach ($pool['sparkline'] as $point)
                        @php $h = max(12, (int) round(((float) $point / $sparklineMax) * 100)); @endphp
                        <div
                            @class([
            'flex-1 rounded-sm',
            'bg-red-400/70 dark:bg-red-500/60' => ($pool['has_drift'] ?? false),
            'bg-sky-400/70 dark:bg-sky-500/60' => !($pool['has_drift'] ?? false),
        ])
                            style="height: {{ $h }}%"
                            title="{{ \App\Support\Insights\InsightFormatter::money($point) }}"
                        ></div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- ── Row 2: Loan queue preview (left, wide) + Recon alerts (right) ── --}}
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-5">

        {{-- Loan queue preview table --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 lg:col-span-3">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-queue-list class="h-4 w-4 text-amber-500" />
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Loan queue') }} — {{ __('top requests') }}</span>
                </div>
                <a href="{{ \App\Filament\Tenant\Resources\Loans\LoanResource::getUrl('queue') }}"
                    class="text-[11px] font-medium text-sky-600 hover:underline dark:text-sky-400">{{ __('View all →') }}</a>
            </div>
            @if (count($loanQueue) > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-[12px]">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/60">
                                <th class="px-4 py-2 text-start text-[10px] font-semibold uppercase tracking-wide text-gray-400">#</th>
                                <th class="px-4 py-2 text-start text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('Member') }}</th>
                                <th class="px-4 py-2 text-start text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('Amount') }}</th>
                                <th class="px-4 py-2 text-start text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('Type') }}</th>
                                <th class="px-4 py-2 text-start text-[10px] font-semibold uppercase tracking-wide text-gray-400"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($loanQueue as $i => $loan)
                                <tr class="border-b border-gray-50 transition last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/40">
                                    <td class="px-4 py-2.5 text-gray-400">{{ $i + 1 }}</td>
                                    <td class="px-4 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-sky-50 text-[9px] font-bold text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">
                                                {{ $loan['member_initials'] }}
                                            </div>
                                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ $loan['member_name'] }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5 font-semibold text-gray-800 dark:text-gray-200">
                                        <x-member::amount :value="$loan['amount']" />
                                    </td>
                                    <td class="px-4 py-2.5">
                                        @if ($loan['is_emergency'])
                                            <span class="inline-block rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-950/40 dark:text-red-400">{{ __('Emergency') }}</span>
                                        @else
                                            <span class="inline-block rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold text-sky-700 dark:bg-sky-950/40 dark:text-sky-400">{{ __('Standard') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <a href="{{ $loan['url'] }}"
                                            class="ff-tenant-btn inline-flex items-center gap-1 px-3 py-1 text-[11px]">
                                            {{ __('Review') }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="flex flex-col items-center justify-center gap-2 px-4 py-8 text-center">
                    <x-heroicon-o-check-circle class="h-8 w-8 text-emerald-400" />
                    <p class="text-[12px] font-medium text-gray-500">{{ __('Queue clear') }}</p>
                    <p class="text-[11px] text-gray-400">{{ __('No loans awaiting review') }}</p>
                </div>
            @endif
        </div>

        {{-- Recon / Attention alerts (right) --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 lg:col-span-2">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-shield-exclamation class="h-4 w-4 text-red-500" />
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Alerts') }}</span>
                </div>
                <a href="{{ \App\Filament\Tenant\Pages\ReconciliationOverviewPage::getUrl() }}"
                    class="text-[11px] font-medium text-sky-600 hover:underline dark:text-sky-400">{{ __('Open queue →') }}</a>
            </div>
            <div class="space-y-2 p-3">
                @foreach ($d['attention_cards'] as $card)
                    @php
    $noticeClass = match ($card['tone'] ?? '') {
        'rose' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-800/40 dark:bg-red-950/30 dark:text-red-300',
        'amber', 'warning' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800/40 dark:bg-amber-950/30 dark:text-amber-300',
        'emerald', 'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-950/30 dark:text-emerald-300',
        default => 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800/40 dark:bg-sky-950/30 dark:text-sky-300',
    };
                    @endphp
                    <a href="{{ $card['url'] }}"
                        class="flex items-start gap-2.5 rounded-lg border px-3 py-2 transition hover:opacity-80 {{ $noticeClass }}">
                        <x-dynamic-component :component="$card['icon']" class="mt-0.5 h-4 w-4 shrink-0" />
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold">{{ $card['title'] }}</p>
                            <p class="text-[11px] opacity-80">{{ $card['body'] }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── Row 3: Collection progress (left) + Activity feed (center) + Fund gauges (right) ── --}}
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">

        {{-- Collection cycle progress bars --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                <x-heroicon-o-calendar-days class="h-4 w-4 text-sky-500" />
                <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Cycle collection progress') }}</span>
            </div>
            <div class="space-y-2.5 p-4">
                @php
$bars = [
    ['label' => __('Collected'), 'pct' => $breakdown['posted_pct'], 'color' => '#1D9E75', 'count' => $breakdown['posted']],
    ['label' => __('Pending'), 'pct' => $breakdown['pending_pct'], 'color' => '#EF9F27', 'count' => $breakdown['pending']],
    ['label' => __('Failed'), 'pct' => $breakdown['failed_pct'], 'color' => '#E24B4A', 'count' => $breakdown['failed']],
    ['label' => __('Waived'), 'pct' => $breakdown['waived_pct'], 'color' => '#9ca3af', 'count' => $breakdown['waived']],
];
$lateFeeTiers = [
    ['label' => __('Tier 1 (day 3+)'), 'count' => $breakdown['tier1'], 'class' => 'text-amber-600 dark:text-amber-400'],
    ['label' => __('Tier 2 (day 10+)'), 'count' => $breakdown['tier2'], 'class' => 'text-orange-600 dark:text-orange-400'],
    ['label' => __('Tier 3 (day 20+)'), 'count' => $breakdown['tier3'], 'class' => 'text-red-600 dark:text-red-400'],
];
                @endphp
                @foreach ($bars as $bar)
                    <div class="flex items-center gap-2">
                        <span class="w-20 text-[11px] text-gray-500 dark:text-gray-400 ltr:text-right rtl:text-left">{{ $bar['label'] }}</span>
                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full transition-all duration-700" style="width: {{ $bar['pct'] }}%; background: {{ $bar['color'] }}"></div>
                        </div>
                        <span class="w-7 text-[11px] font-semibold tabular-nums text-gray-700 dark:text-gray-300">{{ $bar['pct'] }}%</span>
                    </div>
                @endforeach

                <div class="mt-2 border-t border-gray-100 pt-3 dark:border-gray-700">
                    <p class="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('Late fee tiers') }}</p>
                    @foreach ($lateFeeTiers as $tier)
                        <div class="flex items-center justify-between text-[11px]">
                            <span class="text-gray-500">{{ $tier['label'] }}</span>
                            <span class="{{ $tier['class'] }} font-semibold">
                                {{ $tier['count'] > 0 ? trans_choice(':n member|:n members', $tier['count'], ['n' => $tier['count']]) : '—' }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Recent member activity feed --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-bolt class="h-4 w-4 text-violet-500" />
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Recent activity') }}</span>
                </div>
                <a href="{{ \App\Filament\Tenant\Resources\FundAuditLogs\FundAuditLogResource::getUrl('index') }}"
                    class="text-[11px] font-medium text-sky-600 hover:underline dark:text-sky-400">{{ __('All →') }}</a>
            </div>
            <div class="divide-y divide-gray-50 px-1 dark:divide-gray-800">
                @forelse ($activity as $event)
                    @php
    $chipClass = match ($event['chip']['class']) {
        'ff-chip-green' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400',
        'ff-chip-amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400',
        'ff-chip-blue' => 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-400',
        'ff-chip-purple' => 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-400',
        default => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
    };
    $avatarClass = match ($event['chip']['class']) {
        'ff-chip-green' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
        'ff-chip-blue' => 'bg-sky-50 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
        'ff-chip-amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        'ff-chip-purple' => 'bg-violet-50 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
        default => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
    };
                    @endphp
                    <div class="flex items-center gap-2.5 px-3 py-2.5">
                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[9px] font-bold {{ $avatarClass }}">
                            {{ $event['initials'] }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[11px] font-medium text-gray-800 dark:text-gray-200">{{ $event['member_name'] }}</p>
                            <p class="truncate text-[10px] text-gray-400">{{ $event['description'] }}</p>
                        </div>
                        <span class="shrink-0 rounded-full px-1.5 py-0.5 text-[9px] font-semibold {{ $chipClass }}">{{ $event['chip']['label'] }}</span>
                    </div>
                @empty
                    <div class="px-4 py-6 text-center text-[11px] text-gray-400">{{ __('No recent activity') }}</div>
                @endforelse
            </div>
        </div>

        {{-- Fund tier utilisation ── --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-chart-pie class="h-4 w-4 text-indigo-500" />
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Fund tier utilisation') }}</span>
                </div>
                <a href="{{ \App\Filament\Tenant\Resources\FundTiers\FundTierResource::getUrl('index') }}"
                    class="text-[11px] font-medium text-sky-600 hover:underline dark:text-sky-400">{{ __('Manage →') }}</a>
            </div>
            <div class="space-y-3 p-4">
                @forelse ($d['fund_tier_utilisation'] as $tier)
                    @php
    $tierColor = match ($tier['tone']) {
        'danger' => 'text-red-600 dark:text-red-400',
        'warning' => 'text-amber-600 dark:text-amber-400',
        default => 'text-emerald-600 dark:text-emerald-400',
    };
                    @endphp
                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <span class="text-[11px] font-medium text-gray-600 dark:text-gray-400">{{ $tier['label'] }}</span>
                            <span class="{{ $tierColor }} text-[11px] font-bold tabular-nums">{{ $tier['pct'] }}%</span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full transition-all duration-700"
                                style="width: {{ $tier['pct'] }}%; background: {{ $tier['bar_color'] }}"></div>
                        </div>
                        @if ($tier['tone'] === 'danger')
                            <p class="mt-0.5 text-[10px] text-red-500">⚠ {{ __('Near capacity') }} · {!! \App\Support\Insights\InsightFormatter::moneyMarkup($tier['available_amount']) !!} {{ __('available') }}</p>
                        @else
                            <p class="mt-0.5 text-[10px] text-gray-400">{!! \App\Support\Insights\InsightFormatter::moneyMarkup($tier['available_amount']) !!} {{ __('available') }}</p>
                        @endif
                    </div>
                @empty
                    <p class="py-4 text-center text-[11px] text-gray-400">{{ __('No active fund tiers configured') }}</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ── Row 4: Fund health gauges + loan pipeline (compact strip) ── --}}
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-5">
        {{-- 4 health gauges --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 lg:col-span-3">
            <div class="grid grid-cols-4 divide-x divide-gray-100 dark:divide-gray-700">
                @foreach ($d['gauges'] as $gauge)
                    @php
    $gt = match ($gauge['tone']) {
        'emerald' => ['ring' => '#1d9e75', 'text' => 'text-emerald-600 dark:text-emerald-400'],
        'amber' => ['ring' => '#ef9f27', 'text' => 'text-amber-600 dark:text-amber-400'],
        'rose' => ['ring' => '#e24b4a', 'text' => 'text-red-600 dark:text-red-400'],
        'sky' => ['ring' => '#0284c7', 'text' => 'text-sky-600 dark:text-sky-400'],
        default => ['ring' => '#6b7280', 'text' => 'text-gray-500 dark:text-gray-400'],
    };
    $circumference = 2 * M_PI * 24;
    $dashOffset = $circumference - ($gauge['percent'] / 100) * $circumference;
                    @endphp
                    <a href="{{ $gauge['url'] }}"
                        class="flex flex-col items-center gap-1 px-2 py-3 transition hover:bg-gray-50 dark:hover:bg-gray-800">
                        <div class="relative h-12 w-12">
                            <svg viewBox="0 0 56 56" class="h-full w-full -rotate-90">
                                <circle cx="28" cy="28" r="24" fill="none" stroke="#e5e7eb" stroke-width="5" class="dark:stroke-gray-700" />
                                <circle cx="28" cy="28" r="24" fill="none"
                                    stroke="{{ $gt['ring'] }}"
                                    stroke-width="5"
                                    stroke-linecap="round"
                                    stroke-dasharray="{{ $circumference }}"
                                    stroke-dashoffset="{{ $dashOffset }}"
                                    class="transition-all duration-700" />
                            </svg>
                            <span class="absolute inset-0 flex items-center justify-center text-[10px] font-bold tabular-nums {{ $gt['text'] }}">{{ $gauge['value'] }}</span>
                        </div>
                        <p class="text-center text-[10px] font-semibold text-gray-500 dark:text-gray-400">{{ $gauge['label'] }}</p>
                        <p class="text-center text-[9px] text-gray-400 dark:text-gray-500">{{ $gauge['sub'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Loan pipeline --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 lg:col-span-2">
            <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2 dark:border-gray-700">
                <x-heroicon-o-funnel class="h-4 w-4 text-sky-500" />
                <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Loan pipeline') }}</span>
            </div>
            <div class="grid grid-cols-4 divide-x divide-gray-100 dark:divide-gray-700">
                <a href="{{ $pipeline['queue_needs_decision_url'] ?? '#' }}"
                    class="flex flex-col items-center py-3 transition hover:bg-amber-50/60 dark:hover:bg-amber-950/20">
                    <span class="text-lg font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['needs_decision'] ?? 0 }}</span>
                    <span class="mt-0.5 text-[9px] font-medium text-gray-400">{{ __('Decision') }}</span>
                </a>
                <a href="{{ $pipeline['queue_ready_to_disburse_url'] ?? '#' }}"
                    class="flex flex-col items-center py-3 transition hover:bg-sky-50/60 dark:hover:bg-sky-950/20">
                    <span class="text-lg font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['ready_to_disburse'] ?? 0 }}</span>
                    <span class="mt-0.5 text-[9px] font-medium text-gray-400">{{ __('Disburse') }}</span>
                </a>
                <a href="{{ $pipeline['loans_active_url'] ?? '#' }}"
                    class="flex flex-col items-center py-3 transition hover:bg-emerald-50/60 dark:hover:bg-emerald-950/20">
                    <span class="text-lg font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['active'] ?? 0 }}</span>
                    <span class="mt-0.5 text-[9px] font-medium text-gray-400">{{ __('Active') }}</span>
                </a>
                <a href="{{ $pipeline['loans_completed_url'] ?? '#' }}"
                    class="flex flex-col items-center py-3 transition hover:bg-gray-50 dark:hover:bg-gray-800">
                    <span class="text-lg font-bold tabular-nums text-gray-500 dark:text-gray-300">{{ $pipeline['completed'] ?? 0 }}</span>
                    <span class="mt-0.5 text-[9px] font-medium text-gray-400">{{ __('Closed') }}</span>
                </a>
            </div>
        </div>
    </div>

    {{-- ── Row 5: Workspace quick-access links ── --}}
    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-widest text-gray-400">{{ __('Workspace') }}</h3>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($d['workspace_sections'] as $section)
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="border-b border-gray-100 bg-gray-50/60 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/60">
                        <h4 class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">{{ $section['title'] }}</h4>
                    </div>
                    <ul class="divide-y divide-gray-50 dark:divide-gray-800">
                        @foreach ($section['links'] as $link)
                            <li>
                                <a href="{{ $link['url'] }}"
                                    class="flex items-center gap-2 px-3 py-2 text-xs transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
                                    <x-dynamic-component :component="$link['icon']" class="h-3.5 w-3.5 shrink-0 text-sky-500" />
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ $link['label'] }}</span>
                                    <x-heroicon-m-chevron-right class="ff-rtl-flip ms-auto h-3 w-3 text-gray-300 dark:text-gray-600" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>

</div>
