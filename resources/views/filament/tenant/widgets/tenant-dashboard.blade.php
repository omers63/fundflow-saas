@php
    $d = $this->getData();
    $greeting = $d['greeting'];
    $maxContrib = max(1, collect($d['contribution_trend'])->max('amount'));
    $maxLoanTrend = max(1, collect($d['loan_trend'])->max('total') ?? 0);
    $pipeline = $d['loan_pipeline'];

    $subToneClass = fn(string $tone): string => match ($tone) {
        'success', 'emerald' => 'text-[var(--ff-success)]',
        'amber', 'warning'   => 'text-[var(--ff-warning)]',
        'danger', 'rose'     => 'text-[var(--ff-danger)]',
        default              => 'text-[var(--ff-muted-light)]',
    };

    $noticeToneClass = fn(string $tone): string => match ($tone) {
        'rose'   => 'ff-notice-red',
        'amber'  => 'ff-notice-amber',
        'emerald','sky','violet','indigo' => 'ff-notice-blue',
        default  => 'ff-notice-blue',
    };
@endphp

<div class="ff-dashboard w-full max-w-none space-y-4 pb-4">

    {{-- ── Greeting hero (slim) ── --}}
    <div class="ff-dashboard-hero relative overflow-hidden rounded-2xl border border-sky-200/50 bg-gradient-to-br from-sky-500 via-sky-600 to-blue-700 px-4 py-3 shadow-md shadow-sky-500/15 sm:px-5 sm:py-4 dark:border-sky-500/20 dark:from-sky-700 dark:via-blue-800 dark:to-indigo-900">
        <div class="pointer-events-none absolute -right-6 -top-6 h-32 w-32 rounded-full bg-white/10 blur-2xl" aria-hidden="true"></div>
        <div class="relative flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-widest text-sky-100/80">{{ $greeting['date'] }}</p>
                <h2 class="mt-0.5 text-lg font-bold text-white">{{ $greeting['period_label'] }}, {{ $greeting['name'] }}</h2>
                <p class="text-xs text-sky-100/70">{{ $greeting['fund_name'] }}</p>
                @if ($greeting['attention_total'] > 0)
                    <p class="mt-1 text-xs text-white/80">{{ $greeting['subtitle'] }}</p>
                @endif
            </div>
            {{-- Master balances --}}
            <div class="flex shrink-0 flex-wrap gap-2 sm:justify-end">
                @foreach ($d['balances'] as $balance)
                    <a href="{{ $balance['url'] }}"
                        class="ff-dashboard-balance group min-w-[6.5rem] rounded-xl bg-white/12 px-3 py-2 backdrop-blur-sm ring-1 ring-white/20 transition hover:bg-white/22">
                        <p class="text-[10px] font-medium uppercase tracking-wide text-sky-100/80">{{ $balance['label'] }}</p>
                        <p class="mt-0.5 text-sm font-bold tabular-nums text-white">{{ $balance['amount'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── KPI stat strip (4 cards) ── --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($d['kpi_stats'] as $stat)
            <a href="{{ $stat['url'] }}"
                class="group flex flex-col gap-1 rounded-xl border border-[var(--ff-border)] bg-[var(--ff-surface)] px-4 py-3 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-[var(--ff-muted-light)]">{{ $stat['label'] }}</p>
                <p class="text-[22px] font-bold tabular-nums text-gray-900 dark:text-white leading-none">{{ $stat['value'] }}</p>
                <p @class(['text-[11px] font-medium', $subToneClass($stat['sub_tone'])])>{{ $stat['sub'] }}</p>
            </a>
        @endforeach
    </div>

    {{-- ── Compact quick-action bar ── --}}
    <div class="flex flex-wrap items-center gap-2 rounded-xl border border-[var(--ff-border)] bg-[var(--ff-surface)] px-4 py-2.5">
        <span class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ff-muted-light)] me-1">{{ __('Quick actions') }}</span>
        @foreach ($d['quick_actions'] as $action)
            <a href="{{ $action['url'] }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--ff-border)] bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 hover:shadow dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                <x-dynamic-component :component="$action['icon']" class="h-3.5 w-3.5 shrink-0 text-sky-500" />
                {{ $action['label'] }}
                @if (filled($action['badge'] ?? null))
                    <span class="ms-0.5 inline-flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-amber-100 px-1 text-[10px] font-bold text-amber-700">{{ $action['badge'] }}</span>
                @endif
            </a>
        @endforeach
    </div>

    {{-- ── Row 2: Needs-attention panel + Loan pipeline ── --}}
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-5">

        {{-- Attention / Recon alerts (left) --}}
        <div class="overflow-hidden rounded-xl border border-[var(--ff-border)] bg-[var(--ff-surface)] lg:col-span-2">
            <div class="flex items-center justify-between border-b border-[var(--ff-border)] px-4 py-2.5">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Needs attention') }}</span>
                <a href="{{ \App\Filament\Tenant\Pages\ReconciliationOverviewPage::getUrl() }}"
                    class="text-[11px] text-[var(--ff-info)] hover:underline">{{ __('View queue →') }}</a>
            </div>
            <div class="space-y-2 p-3">
                @foreach ($d['attention_cards'] as $card)
                    <a href="{{ $card['url'] }}"
                        @class(['ff-notice flex items-start gap-2.5 rounded-lg border px-3 py-2.5 transition hover:opacity-80', $noticeToneClass($card['tone'])])>
                        <x-dynamic-component :component="$card['icon']" class="mt-0.5 h-4 w-4 shrink-0" />
                        <div class="min-w-0">
                            <p class="text-xs font-semibold">{{ $card['title'] }}</p>
                            <p class="text-[11px] opacity-80">{{ $card['body'] }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Loan pipeline (right) --}}
        <div class="overflow-hidden rounded-xl border border-[var(--ff-border)] bg-[var(--ff-surface)] lg:col-span-3">
            <div class="flex items-center justify-between border-b border-[var(--ff-border)] px-4 py-2.5">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-funnel class="h-4 w-4 text-sky-500" />
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Loan pipeline') }}</span>
                </div>
                <span class="text-[10px] text-[var(--ff-muted-light)]">{{ $d['open_period_label'] }}</span>
            </div>
            <div class="grid grid-cols-2 divide-x divide-[var(--ff-border)] sm:grid-cols-4">
                <a href="{{ $pipeline['queue_needs_decision_url'] }}"
                    class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-amber-50/60 dark:hover:bg-amber-950/20">
                    <span class="text-2xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['needs_decision'] }}</span>
                    <span class="mt-0.5 text-[10px] font-medium text-[var(--ff-muted)]">{{ __('Decision') }}</span>
                </a>
                <a href="{{ $pipeline['queue_ready_to_disburse_url'] }}"
                    class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-sky-50/60 dark:hover:bg-sky-950/20">
                    <span class="text-2xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['ready_to_disburse'] }}</span>
                    <span class="mt-0.5 text-[10px] font-medium text-[var(--ff-muted)]">{{ __('Disburse') }}</span>
                </a>
                <a href="{{ $pipeline['loans_active_url'] }}"
                    class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-emerald-50/60 dark:hover:bg-emerald-950/20">
                    <span class="text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['active'] }}</span>
                    <span class="mt-0.5 text-[10px] font-medium text-[var(--ff-muted)]">{{ __('Active') }}</span>
                </a>
                <a href="{{ $pipeline['loans_completed_url'] }}"
                    class="flex flex-col items-center px-3 py-4 text-center transition hover:bg-gray-50/60 dark:hover:bg-gray-900/20">
                    <span class="text-2xl font-bold tabular-nums text-gray-500 dark:text-gray-300">{{ $pipeline['completed'] }}</span>
                    <span class="mt-0.5 text-[10px] font-medium text-[var(--ff-muted)]">{{ __('Closed') }}</span>
                </a>
            </div>
            @if (filled($d['sparkline'] ?? []))
                <div class="flex h-8 items-end gap-px border-t border-[var(--ff-border)] px-3 pt-1.5 pb-1">
                    @foreach ($d['sparkline'] as $point)
                        @php $h = max(15, (int) round(($point / $d['sparkline_max']) * 100)); @endphp
                        <div class="flex-1 rounded-sm bg-gradient-to-t from-sky-400 to-sky-500 dark:from-sky-500 dark:to-sky-400"
                            style="height: {{ $h }}%" title="{{ __('Master ledger activity') }}"></div>
                    @endforeach
                </div>
                <p class="border-t border-[var(--ff-border)] px-3 py-1 text-[10px] text-[var(--ff-muted-light)]">{{ __('7-day master ledger activity') }}</p>
            @endif
        </div>
    </div>

    {{-- ── Row 3: Fund health gauges (compact 4-column) ── --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ($d['gauges'] as $gauge)
            @php
                $gaugeTone = match ($gauge['tone']) {
                    'emerald' => ['ring' => '#1d9e75', 'text' => 'text-emerald-600', 'bg' => 'bg-emerald-50 dark:bg-emerald-950/30'],
                    'amber'   => ['ring' => '#ef9f27', 'text' => 'text-amber-600',   'bg' => 'bg-amber-50 dark:bg-amber-950/30'],
                    'rose'    => ['ring' => '#e24b4a', 'text' => 'text-red-600',     'bg' => 'bg-red-50 dark:bg-red-950/30'],
                    'sky'     => ['ring' => '#0284c7', 'text' => 'text-sky-600',     'bg' => 'bg-sky-50 dark:bg-sky-950/30'],
                    default   => ['ring' => '#6b7280', 'text' => 'text-gray-600',    'bg' => 'bg-gray-50 dark:bg-gray-900/30'],
                };
                $circumference = 2 * M_PI * 30;
                $dashOffset = $circumference - ($gauge['percent'] / 100) * $circumference;
            @endphp
            <a href="{{ $gauge['url'] }}"
                class="group flex flex-col items-center gap-1.5 rounded-xl border border-[var(--ff-border)] bg-[var(--ff-surface)] px-3 py-3 text-center shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                <div class="ff-dashboard-gauge relative h-16 w-16">
                    <svg viewBox="0 0 68 68" class="h-full w-full -rotate-90">
                        <circle cx="34" cy="34" r="30" fill="none" stroke="#e5e7eb" stroke-width="5" />
                        <circle cx="34" cy="34" r="30" fill="none"
                            stroke="{{ $gaugeTone['ring'] }}"
                            stroke-width="5"
                            stroke-linecap="round"
                            stroke-dasharray="{{ $circumference }}"
                            stroke-dashoffset="{{ $dashOffset }}"
                            class="ff-gauge-ring transition-all duration-700" />
                    </svg>
                    <span @class(['absolute inset-0 flex items-center justify-center text-sm font-bold tabular-nums', $gaugeTone['text']])>{{ $gauge['value'] }}</span>
                </div>
                <p class="text-[10px] font-semibold uppercase tracking-wide text-[var(--ff-muted-light)]">{{ $gauge['label'] }}</p>
                <p class="text-[11px] text-[var(--ff-muted)]">{{ $gauge['sub'] }}</p>
            </a>
        @endforeach
    </div>

    {{-- ── Row 4: Charts (contribution trend + loan volume) ── --}}
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        {{-- Contribution trend --}}
        <div class="overflow-hidden rounded-xl border border-[var(--ff-border)] bg-[var(--ff-surface)] shadow-sm">
            <div class="flex items-center justify-between border-b border-[var(--ff-border)] px-4 py-2.5">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-chart-bar class="h-4 w-4 text-emerald-500" />
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Contributions posted') }}</h4>
                </div>
                <span class="text-[10px] text-[var(--ff-muted-light)]">{{ __('6 months') }}</span>
            </div>
            <div class="px-4 py-3">
                <div class="flex h-20 items-end gap-1.5">
                    @foreach ($d['contribution_trend'] as $month)
                        @php $barH = max(8, (int) round(($month['amount'] / $maxContrib) * 100)); @endphp
                        <div class="group flex flex-1 flex-col items-center gap-0.5">
                            <span class="text-[10px] font-semibold tabular-nums text-gray-500 opacity-0 transition group-hover:opacity-100"
                                title="{{ $month['amount_formatted'] }}">{{ $month['count'] ?: '·' }}</span>
                            <div class="flex w-full max-w-[2rem] items-end justify-center overflow-hidden rounded-t-sm bg-gray-100 dark:bg-gray-700" style="height: 4rem">
                                <div class="w-full rounded-t-sm bg-gradient-to-t from-emerald-500 to-teal-400 transition-all duration-500 group-hover:from-emerald-400"
                                    style="height: {{ $barH }}%"></div>
                            </div>
                            <span class="text-[10px] text-[var(--ff-muted-light)]">{{ $month['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Loan volume --}}
        <div class="overflow-hidden rounded-xl border border-[var(--ff-border)] bg-[var(--ff-surface)] shadow-sm">
            <div class="flex flex-wrap items-center justify-between border-b border-[var(--ff-border)] px-4 py-2.5">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-chart-bar class="h-4 w-4 text-sky-500" />
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Loan volume') }}</h4>
                </div>
                <div class="flex flex-wrap gap-2 text-[10px] text-[var(--ff-muted)]">
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-emerald-500"></span>{{ __('Active') }}</span>
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-amber-400"></span>{{ __('Pending') }}</span>
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-sm bg-sky-500"></span>{{ __('Closed') }}</span>
                </div>
            </div>
            <div class="px-4 py-3">
                <div class="flex h-20 items-end gap-1.5">
                    @foreach ($d['loan_trend'] as $month)
                        @php
                            $stackTotal = max(1, $month['total']);
                            $activeH = round(($month['active'] / $stackTotal) * 100);
                            $pendingH = round(($month['pending'] / $stackTotal) * 100);
                            $completedH = max(0, 100 - $activeH - $pendingH);
                            $barH = max(12, (int) round(($month['total'] / $maxLoanTrend) * 100));
                        @endphp
                        <div class="flex flex-1 flex-col items-center gap-0.5">
                            <span class="text-[10px] font-semibold tabular-nums text-gray-500">{{ $month['total'] ?: '·' }}</span>
                            <div class="flex w-full max-w-[2rem] flex-col justify-end overflow-hidden rounded-t-sm ring-1 ring-gray-200/60 dark:ring-gray-600"
                                style="height: {{ $barH }}%">
                                @if ($month['active'] > 0)
                                    <div class="w-full bg-emerald-500" style="height: {{ max(3, $activeH) }}%"></div>
                                @endif
                                @if ($month['pending'] > 0)
                                    <div class="w-full bg-amber-400" style="height: {{ max(3, $pendingH) }}%"></div>
                                @endif
                                @if ($month['completed'] > 0)
                                    <div class="w-full bg-sky-500" style="height: {{ max(3, $completedH) }}%"></div>
                                @endif
                                @if ($month['total'] === 0)
                                    <div class="h-0.5 w-full bg-gray-200 dark:bg-gray-600"></div>
                                @endif
                            </div>
                            <span class="text-[10px] text-[var(--ff-muted-light)]">{{ $month['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- ── Row 5: Workspace links (all pages accessible from dashboard) ── --}}
    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-widest text-[var(--ff-muted-light)]">{{ __('Workspace') }}</h3>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($d['workspace_sections'] as $section)
                <div class="overflow-hidden rounded-xl border border-[var(--ff-border)] bg-[var(--ff-surface)] shadow-sm">
                    <div class="border-b border-[var(--ff-border)] bg-gray-50/60 px-3 py-2 dark:bg-gray-800/60">
                        <h4 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ff-muted)]">{{ $section['title'] }}</h4>
                    </div>
                    <ul class="divide-y divide-[var(--ff-border)]">
                        @foreach ($section['links'] as $link)
                            <li>
                                <a href="{{ $link['url'] }}"
                                    class="flex items-center gap-2 px-3 py-2 text-xs transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
                                    <x-dynamic-component :component="$link['icon']" class="h-3.5 w-3.5 shrink-0 text-sky-500" />
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ $link['label'] }}</span>
                                    <x-heroicon-m-chevron-right class="ms-auto h-3 w-3 text-gray-300" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>

</div>
