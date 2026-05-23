@php
    $d = $this->getData();
    $pipeline = $d['pipeline'];
    $fund = $d['fund'];
    $maxTrend = max(1, collect($d['trend'])->max('total'));
    $maxStatus = max(1, collect($d['status_breakdown'])->max('count'));
    $sparkMax = max(1, max($d['sparkline']));
    $currency = $fund['currency'];
    $accentBar = ['amber' => 'bg-amber-500', 'emerald' => 'bg-emerald-500', 'rose' => 'bg-rose-500', 'sky' => 'bg-sky-500', 'violet' => 'bg-violet-500', 'teal' => 'bg-teal-500'];
    $accentIcon = ['amber' => 'text-amber-500', 'emerald' => 'text-emerald-500', 'rose' => 'text-rose-500', 'sky' => 'text-sky-500', 'violet' => 'text-violet-500', 'teal' => 'text-teal-500'];
    $kpis = \App\Support\Insights\InsightKpi::linkMany([
        ['key' => 'active', 'label' => __('Active'), 'value' => $d['active'], 'sub' => __('Members'), 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true],
        ['key' => 'delinquent', 'label' => __('Delinquent'), 'value' => $d['delinquent'], 'sub' => __('Attention'), 'icon' => 'heroicon-o-exclamation-triangle', 'accent' => 'rose', 'active' => $d['delinquent'] > 0],
        ['key' => 'total', 'label' => __('Roster'), 'value' => $d['total'], 'sub' => __(':count inactive', ['count' => $d['inactive']]), 'icon' => 'heroicon-o-users', 'accent' => 'sky', 'active' => true],
        ['key' => 'new', 'label' => __('Joined/mo'), 'value' => $d['new_this_month'], 'sub' => $d['mom_change'] !== null ? __(':percent%', ['percent' => $d['mom_change']]) : now()->format('M'), 'icon' => 'heroicon-o-sparkles', 'accent' => 'violet', 'active' => true, 'mom' => $d['mom_change']],
        ['key' => 'dependents', 'label' => __('Dependents'), 'value' => $d['dependents'], 'sub' => __(':count heads', ['count' => $d['independent']]), 'icon' => 'heroicon-o-user-group', 'accent' => 'teal', 'active' => $d['dependents'] > 0],
        ['key' => 'avg', 'label' => __('Avg contrib'), 'value' => number_format($d['avg_contribution'], 0), 'sub' => $currency, 'icon' => 'heroicon-o-banknotes', 'accent' => 'amber', 'active' => $d['avg_contribution'] > 0],
    ], [
        'active' => $pipeline['members_url'].'?tableFilters[status][value]=active',
        'delinquent' => $pipeline['members_url'].'?tableFilters[status][value]=delinquent',
        'total' => $pipeline['members_url'],
        'new' => $pipeline['members_url'],
        'dependents' => $pipeline['members_url'].'?tableFilters[has_dependents][value]=1',
        'avg' => \App\Filament\Tenant\Resources\Contributions\ContributionResource::getUrl('index'),
    ]);
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1">
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
        <div @class([
            'ff-app-insights-hero overflow-hidden rounded-xl border px-3 py-2.5 shadow-sm lg:col-span-1',
            'border-amber-200/80 bg-gradient-to-r from-amber-50 to-emerald-50/80 dark:border-amber-500/30 dark:from-amber-950/40 dark:to-emerald-950/20' => $d['needs_attention'] > 0,
            'border-emerald-200/70 bg-gradient-to-r from-emerald-50 to-teal-50/60 dark:border-emerald-500/25 dark:from-emerald-950/30 dark:to-teal-950/20' => $d['needs_attention'] === 0,
        ])>
            <div class="flex items-center justify-between gap-2">
                @if ($d['needs_attention'] > 0)
                    <div class="flex min-w-0 items-center gap-2">
                        <x-heroicon-o-users class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                        <div class="min-w-0">
                            <p class="truncate text-xs font-semibold text-amber-900 dark:text-amber-100">
                                {{ __('Members need your attention') }}</p>
                            <p class="truncate text-[11px] text-gray-600 dark:text-gray-400">
                                {{ trans_choice(':count delinquent|:count delinquent', $d['delinquent'], ['count' => $d['delinquent']]) }}
                                @if ($d['suspended'] > 0)
                                    · {{ trans_choice(':count suspended|:count suspended', $d['suspended'], ['count' => $d['suspended']]) }}
                                @endif
                                @if ($d['zero_cash_members'] > 0)
                                    · <span
                                        class="text-red-600 dark:text-red-400">{{ trans_choice(':count zero cash|:count zero cash', $d['zero_cash_members'], ['count' => $d['zero_cash_members']]) }}</span>
                                @endif
                            </p>
                        </div>
                    </div>
                    <a href="{{ $pipeline['members_url'] }}?tableFilters[status][value]=delinquent"
                        class="shrink-0 rounded-lg bg-amber-600 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-amber-500 dark:bg-amber-500">
                        {{ __('Review') }}
                    </a>
                @else
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-check-badge class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                        <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ __('Roster healthy') }}</p>
                    </div>
                @endif
            </div>
        </div>

        @include('filament.tenant.widgets.partials.insights-kpi-strip', [
            'kpis' => $kpis,
            'sparkline' => $d['needs_attention'] > 0 ? $d['sparkline'] : null,
            'sparklineMax' => $sparkMax,
        ])
    </div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-funnel class="h-4 w-4 text-emerald-500" />
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Pipeline') }}
                    </h3>
                </div>
                @if ($d['new_this_month'] > 0)
                    <span class="text-[10px] font-medium text-violet-700 dark:text-violet-300">+{{ $d['new_this_month'] }}
                        {{ __('this mo') }}</span>
                @endif
            </div>
            <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700">
                <a href="{{ $pipeline['members_url'] }}?tableFilters[status][value]=active"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-emerald-50/70 dark:hover:bg-emerald-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['active_members'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Active') }}</span>
                </a>
                <a href="{{ $pipeline['members_url'] }}?tableFilters[status][value]=delinquent"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-rose-50/70 dark:hover:bg-rose-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-rose-600 dark:text-rose-400">{{ $pipeline['delinquent_members'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Delinquent') }}</span>
                </a>
                <a href="{{ $pipeline['contributions_url'] }}"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['dependents'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Dependents') }}</span>
                </a>
            </div>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 md:col-span-1">
            <div
                class="grid grid-cols-1 divide-y divide-gray-100 dark:divide-gray-700 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                <div class="px-3 py-2.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('Status mix') }}</p>
                    <div class="mt-2 space-y-1.5">
                        @foreach ($d['status_breakdown'] as $tier)
                            @php $width = $maxStatus > 0 ? round(($tier['count'] / $maxStatus) * 100) : 0; @endphp
                            <div>
                                <div class="mb-0.5 flex justify-between text-[10px]">
                                    <span class="text-gray-600 dark:text-gray-300">{{ $tier['label'] }}</span>
                                    <span class="tabular-nums text-gray-400">{{ $tier['count'] }}</span>
                                </div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                    <div class="h-full rounded-full bg-gradient-to-r from-sky-500 to-violet-500"
                                        style="width: {{ max($tier['count'] > 0 ? 6 : 0, $width) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="px-3 py-2.5">
                    <div class="flex items-center gap-1">
                        <x-heroicon-o-document-check class="h-3.5 w-3.5 text-emerald-500" />
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ __('Fund health') }}</p>
                    </div>
                    <p class="mt-1.5 text-lg font-bold tabular-nums text-gray-900 dark:text-white">
                        {{ number_format($fund['avg_contribution'], 0) }} <span
                            class="text-[10px] font-normal text-gray-400">{{ $currency }}</span>
                    </p>
                    <p class="text-[10px] text-gray-400">
                        {{ trans_choice(':count active loans|:count active loans', $fund['active_loans'], ['count' => $fund['active_loans']]) }}
                    </p>
                    <div class="mt-2">
                        <div class="mb-0.5 flex justify-between text-[10px] text-gray-500">
                            <span>{{ __('Loan-exempt') }}</span>
                            <span class="font-semibold text-amber-600">{{ $fund['loan_exempt'] }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-amber-500" style="width: {{ min(100, $fund['loan_exempt'] * 12) }}%"></div>
                        </div>
                    </div>
                    <div class="mt-2">
                        <div class="mb-0.5 flex justify-between text-[10px] text-gray-500">
                            <span>{{ __('Zero cash') }}</span>
                            <span class="font-semibold text-rose-600">{{ $fund['zero_cash'] }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-rose-500" style="width: {{ min(100, $fund['zero_cash'] * 15) }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 items-stretch gap-3 md:grid-cols-2">
        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-queue-list class="h-4 w-4 text-amber-500" />
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('Attention queue') }}</h4>
                </div>
                @if ($d['zero_cash_members'] > 0)
                    <span
                        class="rounded bg-red-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ __('SLA') }}</span>
                @endif
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($d['attention_queue'] as $member)
                    <a href="{{ $member['view_url'] }}"
                        class="flex items-center gap-2 px-3 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-[10px] font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                            {{ strtoupper(substr($member['name'], 0, 1)) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-medium text-gray-900 dark:text-white">{{ $member['name'] }}</p>
                            <p class="truncate text-[10px] text-gray-400">{{ $member['contribution'] }} ·
                                {{ $member['status'] }}</p>
                        </div>
                        <span @class([
                            'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                            'bg-rose-100 text-rose-800 dark:bg-rose-900/40' => $member['status_key'] === 'delinquent',
                            'bg-amber-100 text-amber-800 dark:bg-amber-900/40' => $member['status_key'] === 'suspended',
                        ])>
                            {{ $member['status'] }}
                        </span>
                    </a>
                @empty
                    <div class="px-3 py-6 text-center">
                        <x-heroicon-o-check-circle class="mx-auto h-6 w-6 text-emerald-400" />
                        <p class="mt-1 text-xs text-gray-500">{{ __('Queue is empty') }}</p>
                    </div>
                @endforelse
            </div>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-chart-bar class="h-4 w-4 text-indigo-500" />
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('6-month membership growth') }}</h4>
                </div>
                <div class="flex flex-wrap gap-3 text-[10px] text-gray-500">
                    <span class="flex items-center gap-1"><span
                            class="h-2 w-2 rounded-sm bg-emerald-500"></span>{{ __('Joined') }}</span>
                    <span class="flex items-center gap-1"><span
                            class="h-2 w-2 rounded-sm bg-rose-500"></span></span>
                    <span class="flex items-center gap-1"><span
                            class="h-2 w-2 rounded-sm bg-amber-400"></span></span>
                </div>
            </div>
            <div class="px-3 py-3">
                <div class="flex h-20 items-end gap-1.5 sm:gap-2">
                    @foreach ($d['trend'] as $month)
                        @php
                            $stackTotal = max(1, $month['total']);
                            $joinedH = round(($month['joined'] / $stackTotal) * 100);
                            $otherH = round(($month['other'] / $stackTotal) * 100);
                            $otherH = max(0, 100 - $joinedH);
                            $barH = max(12, (int) round(($month['total'] / $maxTrend) * 100));
                        @endphp
                        <div class="flex flex-1 flex-col items-center gap-0.5">
                            <span
                                class="text-[10px] font-semibold tabular-nums text-gray-500">{{ $month['total'] ?: '·' }}</span>
                            <div class="flex w-full max-w-[2.25rem] flex-col justify-end overflow-hidden rounded-t-md ring-1 ring-gray-200/60 dark:ring-gray-600"
                                style="height: {{ $barH }}%">
                                @if ($month['joined'] > 0)
                                    <div class="w-full bg-violet-500" style="height: {{ max(3, $joinedH) }}%"></div>
                                @endif
                                @if (false)
                                    <div class="w-full bg-rose-500" style="height: {{ max(3, $rejectedH) }}%"></div>
                                @endif
                                @if (false)
                                    <div class="w-full bg-amber-400" style="height: {{ max(3, $pendingH) }}%"></div>
                                @endif
                                @if ($month['total'] === 0)
                                    <div class="h-0.5 w-full bg-gray-200 dark:bg-gray-600"></div>
                                @endif
                            </div>
                            <span class="text-[10px] text-gray-400">{{ $month['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
