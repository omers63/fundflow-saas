@php
    $d = $this->getData();
    $greeting = $d['greeting'];
    $maxContrib = max(1, collect($d['contribution_trend'])->max('amount'));
    $maxLoanTrend = max(1, collect($d['loan_trend'])->max('total') ?? 0);
    $pipeline = $d['loan_pipeline'];
@endphp

<div class="ff-dashboard w-full max-w-none space-y-4 pb-2">
    {{-- Greeting hero --}}
    <div
        class="ff-dashboard-hero relative overflow-hidden rounded-2xl border border-sky-200/50 bg-gradient-to-br from-sky-500 via-blue-600 to-indigo-700 px-4 py-4 shadow-lg shadow-sky-500/20 sm:px-6 sm:py-5 dark:border-sky-500/20 dark:from-sky-600 dark:via-blue-700 dark:to-indigo-900">
        <div class="pointer-events-none absolute -right-8 -top-8 h-40 w-40 rounded-full bg-white/10 blur-2xl"
            aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-12 left-1/4 h-32 w-32 rounded-full bg-fuchsia-400/20 blur-2xl"
            aria-hidden="true"></div>
        <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-widest text-sky-100/90">{{ $greeting['date'] }}</p>
                <h2 class="mt-1 text-xl font-bold text-white sm:text-2xl">
                    {{ $greeting['period_label'] }}, {{ $greeting['name'] }}
                </h2>
                <p class="mt-0.5 text-sm text-sky-100">{{ $greeting['fund_name'] }}</p>
                <p class="mt-2 max-w-xl text-sm text-white/85">{{ $greeting['subtitle'] }}</p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2 sm:justify-end">
                @foreach ($d['balances'] as $balance)
                    <a href="{{ $balance['url'] }}"
                        class="ff-dashboard-balance group min-w-[7.5rem] rounded-xl bg-white/15 px-3 py-2 backdrop-blur-md ring-1 ring-white/25 transition hover:bg-white/25">
                        <div class="flex items-center gap-1.5">
                            <x-dynamic-component :component="$balance['icon']" class="h-3.5 w-3.5 text-sky-100" />
                            <span
                                class="text-[10px] font-medium uppercase tracking-wide text-sky-100">{{ $balance['label'] }}</span>
                        </div>
                        <p class="mt-0.5 text-sm font-bold tabular-nums text-white">{{ $balance['amount'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Quick actions --}}
    <div>
        <h3 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
            {{ __('Quick actions') }}
        </h3>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ($d['quick_actions'] as $i => $action)
                <a href="{{ $action['url'] }}"
                    class="ff-dashboard-action group relative isolate min-h-[5.25rem] overflow-hidden rounded-xl p-3 text-white shadow-sm ring-1 ring-black/10 transition hover:-translate-y-0.5 hover:shadow-lg dark:ring-white/15"
                    style="animation: ff-stat-in 0.4s ease-out {{ 0.04 + ($i * 0.04) }}s forwards">
                    <div @class(['ff-dashboard-action__bg', 'ff-dashboard-action__bg--' . ($action['tone'] ?? 'cycle')])
                        aria-hidden="true"></div>
                    <div class="relative z-10 flex flex-col gap-1">
                        <div class="flex items-start justify-between gap-1">
                            <x-dynamic-component :component="$action['icon']"
                                class="h-5 w-5 shrink-0 text-white drop-shadow-sm" />
                            @if (filled($action['badge'] ?? null))
                                <span
                                    class="rounded-full bg-white/30 px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-white shadow-sm ring-1 ring-white/40">
                                    {{ $action['badge'] }}
                                </span>
                            @endif
                        </div>
                        <span
                            class="text-xs font-semibold leading-tight text-white drop-shadow-sm">{{ $action['label'] }}</span>
                        <span
                            class="line-clamp-2 text-[10px] leading-snug text-white/95 drop-shadow-sm">{{ $action['description'] }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>

    {{-- Gauges --}}
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:gap-3">
        @foreach ($d['gauges'] as $gauge)
            @include('filament.tenant.widgets.partials.dashboard-gauge', ['gauge' => $gauge])
        @endforeach
    </div>

    {{-- Attention + pipeline --}}
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
        <div class="space-y-2 lg:col-span-1">
            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {{ __('Needs attention') }}
            </h3>
            <div class="space-y-2">
                @foreach ($d['attention_cards'] as $card)
                    @php
                        $cardTone = match ($card['tone']) {
                            'rose' => 'border-rose-200/80 bg-gradient-to-r from-rose-50 to-orange-50/80 dark:border-rose-500/30 dark:from-rose-950/40 dark:to-orange-950/20',
                            'amber' => 'border-amber-200/80 bg-gradient-to-r from-amber-50 to-yellow-50/80 dark:border-amber-500/30 dark:from-amber-950/40 dark:to-yellow-950/20',
                            'emerald' => 'border-emerald-200/70 bg-gradient-to-r from-emerald-50 to-teal-50/60 dark:border-emerald-500/25 dark:from-emerald-950/30 dark:to-teal-950/20',
                            'sky' => 'border-sky-200/80 bg-gradient-to-r from-sky-50 to-indigo-50/80 dark:border-sky-500/30 dark:from-sky-950/40 dark:to-indigo-950/20',
                            'violet' => 'border-violet-200/80 bg-gradient-to-r from-violet-50 to-fuchsia-50/80 dark:border-violet-500/30 dark:from-violet-950/40 dark:to-fuchsia-950/20',
                            default => 'border-indigo-200/80 bg-gradient-to-r from-indigo-50 to-sky-50/80 dark:border-indigo-500/30 dark:from-indigo-950/40 dark:to-sky-950/20',
                        };
                    @endphp
                    <a href="{{ $card['url'] }}" @class(['flex items-center gap-3 rounded-xl border px-3 py-2.5 transition hover:shadow-md', $cardTone])>
                        <x-dynamic-component :component="$card['icon']"
                            class="h-5 w-5 shrink-0 text-gray-700 dark:text-gray-200" />
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ $card['title'] }}</p>
                            <p class="text-[11px] text-gray-600 dark:text-gray-400">{{ $card['body'] }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 lg:col-span-2">
            <div
                class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-funnel class="h-4 w-4 text-sky-500" />
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('Loan pipeline') }}
                    </h3>
                </div>
                <span class="text-[10px] text-gray-400">{{ $d['open_period_label'] }}</span>
            </div>
            <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700 sm:grid-cols-5">
                <a href="{{ $pipeline['queue_url'] }}?tab=needs_decision"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-amber-50/70 dark:hover:bg-amber-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['needs_decision'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Decision') }}</span>
                </a>
                <a href="{{ $pipeline['queue_url'] }}?tab=ready_to_disburse"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['ready_to_disburse'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Disburse') }}</span>
                </a>
                <a href="{{ $pipeline['queue_url'] }}?tab=awaiting_payout"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-indigo-50/70 dark:hover:bg-indigo-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-indigo-600 dark:text-indigo-400">{{ $pipeline['awaiting_payout'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Payout') }}</span>
                </a>
                <a href="{{ $pipeline['loans_url'] }}?tableFilters[status][value]=active"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-emerald-50/70 dark:hover:bg-emerald-950/20">
                    <span
                        class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['active'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Active') }}</span>
                </a>
                <a href="{{ $pipeline['loans_url'] }}?tableFilters[status][value]=completed"
                    class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-gray-50/70 dark:hover:bg-gray-900/20">
                    <span
                        class="text-xl font-bold tabular-nums text-gray-600 dark:text-gray-300">{{ $pipeline['completed'] }}</span>
                    <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Closed') }}</span>
                </a>
            </div>
            @if (filled($d['sparkline'] ?? []))
                <div class="flex h-6 items-end gap-px border-t border-gray-100 px-3 py-1.5 dark:border-gray-700">
                    @foreach ($d['sparkline'] as $point)
                        @php $h = max(15, (int) round(($point / $d['sparkline_max']) * 100)); @endphp
                        <div class="flex-1 rounded-sm bg-gradient-to-t from-sky-400 to-indigo-500 dark:from-sky-500 dark:to-indigo-400"
                            style="height: {{ $h }}%" title="{{ __('Master ledger activity') }}"></div>
                    @endforeach
                </div>
                <p class="border-t border-gray-100 px-3 py-1 text-[10px] text-gray-400 dark:border-gray-700">
                    {{ __('7-day master ledger activity') }}
                </p>
            @endif
        </div>
    </div>

    {{-- Charts row --}}
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-chart-bar class="h-4 w-4 text-emerald-500" />
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('Contributions posted') }}
                    </h4>
                </div>
                <span class="text-[10px] text-gray-400">{{ __('6 months') }}</span>
            </div>
            <div class="px-3 py-3">
                <div class="flex h-24 items-end gap-1.5 sm:gap-2">
                    @foreach ($d['contribution_trend'] as $month)
                        @php $barH = max(8, (int) round(($month['amount'] / $maxContrib) * 100)); @endphp
                        <div class="group flex flex-1 flex-col items-center gap-0.5">
                            <span
                                class="text-[10px] font-semibold tabular-nums text-gray-500 opacity-0 transition group-hover:opacity-100"
                                title="{{ $month['amount_formatted'] }}">{{ $month['count'] ?: '·' }}</span>
                            <div class="flex w-full max-w-[2.5rem] items-end justify-center overflow-hidden rounded-t-md bg-gray-100 dark:bg-gray-700"
                                style="height: 5rem">
                                <div class="w-full rounded-t-md bg-gradient-to-t from-emerald-500 via-teal-500 to-cyan-400 transition-all duration-500 group-hover:from-emerald-400"
                                    style="height: {{ $barH }}%"></div>
                            </div>
                            <span class="text-[10px] text-gray-400">{{ $month['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-chart-bar class="h-4 w-4 text-indigo-500" />
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Loan volume') }}
                    </h4>
                </div>
                <div class="flex flex-wrap gap-2 text-[10px] text-gray-500">
                    <span class="flex items-center gap-1"><span
                            class="h-2 w-2 rounded-sm bg-emerald-500"></span>{{ __('Active') }}</span>
                    <span class="flex items-center gap-1"><span
                            class="h-2 w-2 rounded-sm bg-amber-400"></span>{{ __('Pending') }}</span>
                    <span class="flex items-center gap-1"><span
                            class="h-2 w-2 rounded-sm bg-violet-500"></span>{{ __('Closed') }}</span>
                </div>
            </div>
            <div class="px-3 py-3">
                <div class="flex h-24 items-end gap-1.5 sm:gap-2">
                    @foreach ($d['loan_trend'] as $month)
                        @php
                            $stackTotal = max(1, $month['total']);
                            $activeH = round(($month['active'] / $stackTotal) * 100);
                            $pendingH = round(($month['pending'] / $stackTotal) * 100);
                            $completedH = max(0, 100 - $activeH - $pendingH);
                            $barH = max(12, (int) round(($month['total'] / $maxLoanTrend) * 100));
                        @endphp
                        <div class="flex flex-1 flex-col items-center gap-0.5">
                            <span
                                class="text-[10px] font-semibold tabular-nums text-gray-500">{{ $month['total'] ?: '·' }}</span>
                            <div class="flex w-full max-w-[2.25rem] flex-col justify-end overflow-hidden rounded-t-md ring-1 ring-gray-200/60 dark:ring-gray-600"
                                style="height: {{ $barH }}%">
                                @if ($month['active'] > 0)
                                    <div class="w-full bg-emerald-500" style="height: {{ max(3, $activeH) }}%"></div>
                                @endif
                                @if ($month['pending'] > 0)
                                    <div class="w-full bg-amber-400" style="height: {{ max(3, $pendingH) }}%"></div>
                                @endif
                                @if ($month['completed'] > 0)
                                    <div class="w-full bg-violet-500" style="height: {{ max(3, $completedH) }}%"></div>
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

    {{-- Workspace links --}}
    <div>
        <h3 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
            {{ __('Workspace') }}
        </h3>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($d['workspace_sections'] as $section)
                <div
                    class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div
                        class="border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white px-3 py-2 dark:border-gray-700 dark:from-gray-800 dark:to-gray-900">
                        <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-300">
                            {{ $section['title'] }}
                        </h4>
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($section['links'] as $link)
                            <li>
                                <a href="{{ $link['url'] }}"
                                    class="flex items-center gap-2 px-3 py-2 text-xs transition hover:bg-sky-50/80 dark:hover:bg-sky-950/30">
                                    <x-dynamic-component :component="$link['icon']"
                                        class="h-4 w-4 shrink-0 text-sky-500 dark:text-sky-400" />
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ $link['label'] }}</span>
                                    <x-heroicon-m-chevron-right class="ms-auto h-3.5 w-3.5 text-gray-300" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>
</div>