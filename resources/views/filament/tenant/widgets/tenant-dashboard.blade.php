@php
    $d = $this->getData();
    $breakdown = $d['collection_breakdown'];
    $loanQueue = $d['loan_queue_preview'];
    $activity = $d['recent_activity'];
    $pipeline = $d['loan_pipeline'];
    $greeting = $d['greeting'];
@endphp

<div class="w-full max-w-none space-y-3 pb-6">

    {{-- ── Slim context banner ── --}}
    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-sky-200 bg-white px-4 py-2.5 shadow-sm dark:border-sky-800/40 dark:bg-gray-900">
        <div class="flex items-center gap-2.5">
            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-sky-600 text-white">
                <x-heroicon-o-building-library class="h-4 w-4" />
            </div>
            <div>
                <p class="text-[11px] font-semibold text-gray-800 dark:text-gray-100">{{ $greeting['fund_name'] }}</p>
                <p class="text-[10px] text-gray-400">{{ $greeting['date'] }}</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1 rounded-md bg-sky-50 px-2.5 py-1 text-[10px] font-semibold text-sky-700 ring-1 ring-inset ring-sky-200 dark:bg-sky-950/40 dark:text-sky-300">
                <x-heroicon-o-calendar-days class="h-3 w-3" />
                {{ __('Cycle') }}: {{ $d['open_period_label'] }}
            </span>
            @foreach ($d['balances'] as $balance)
                <a href="{{ $balance['url'] }}"
                    class="inline-flex items-center gap-1 rounded-md bg-gray-50 px-2.5 py-1 text-[10px] font-semibold text-gray-600 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700">
                    {{ $balance['label'] }}: <span class="font-bold text-gray-900 dark:text-white">{{ $balance['amount'] }}</span>
                </a>
            @endforeach
        </div>
    </div>

    {{-- ── 4 KPI stat cards ── --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($d['kpi_stats'] as $stat)
            @php
                $subColor = match ($stat['sub_tone'] ?? '') {
                    'success', 'emerald' => 'text-emerald-600 dark:text-emerald-400',
                    'amber', 'warning'   => 'text-amber-600 dark:text-amber-400',
                    'danger', 'rose'     => 'text-red-600 dark:text-red-400',
                    default              => 'text-gray-400',
                };
            @endphp
            <a href="{{ $stat['url'] }}"
                class="group flex flex-col gap-1 rounded-xl border border-gray-200 bg-white px-4 py-3.5 shadow-sm transition hover:-translate-y-0.5 hover:border-sky-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-900">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ $stat['label'] }}</p>
                <p class="text-[26px] font-bold tabular-nums leading-none text-gray-900 dark:text-white">{{ $stat['value'] }}</p>
                <p class="{{ $subColor }} text-[11px] font-medium">{{ $stat['sub'] }}</p>
            </a>
        @endforeach
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
                                <th class="px-4 py-2 text-left text-[10px] font-semibold uppercase tracking-wide text-gray-400">#</th>
                                <th class="px-4 py-2 text-left text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('Member') }}</th>
                                <th class="px-4 py-2 text-left text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('Amount') }}</th>
                                <th class="px-4 py-2 text-left text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('Type') }}</th>
                                <th class="px-4 py-2 text-left text-[10px] font-semibold uppercase tracking-wide text-gray-400"></th>
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
                                    <td class="px-4 py-2.5 font-semibold tabular-nums text-gray-800 dark:text-gray-200">{{ $loan['amount'] }}</td>
                                    <td class="px-4 py-2.5">
                                        @if ($loan['is_emergency'])
                                            <span class="inline-block rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-950/40 dark:text-red-400">{{ __('Emergency') }}</span>
                                        @else
                                            <span class="inline-block rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold text-sky-700 dark:bg-sky-950/40 dark:text-sky-400">{{ __('Standard') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <a href="{{ $loan['url'] }}"
                                            class="inline-flex items-center gap-1 rounded-lg bg-sky-600 px-3 py-1 text-[11px] font-semibold text-white transition hover:bg-sky-500">
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
                            'rose'            => 'border-red-200 bg-red-50 text-red-800 dark:border-red-800/40 dark:bg-red-950/30 dark:text-red-300',
                            'amber', 'warning'=> 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800/40 dark:bg-amber-950/30 dark:text-amber-300',
                            'emerald','success'=> 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-950/30 dark:text-emerald-300',
                            default           => 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800/40 dark:bg-sky-950/30 dark:text-sky-300',
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
                        ['label' => __('Pending'),   'pct' => $breakdown['pending_pct'], 'color' => '#EF9F27', 'count' => $breakdown['pending']],
                        ['label' => __('Failed'),    'pct' => $breakdown['failed_pct'],  'color' => '#E24B4A', 'count' => $breakdown['failed']],
                        ['label' => __('Waived'),    'pct' => $breakdown['waived_pct'],  'color' => '#9ca3af', 'count' => $breakdown['waived']],
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
                    @foreach ([
                        ['label' => __('Tier 1 (day 3+)'),  'count' => $breakdown['tier1'], 'class' => 'text-amber-600 dark:text-amber-400'],
                        ['label' => __('Tier 2 (day 10+)'), 'count' => $breakdown['tier2'], 'class' => 'text-orange-600 dark:text-orange-400'],
                        ['label' => __('Tier 3 (day 20+)'), 'count' => $breakdown['tier3'], 'class' => 'text-red-600 dark:text-red-400'],
                    ] as $tier)
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
                            'ff-chip-green'  => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400',
                            'ff-chip-amber'  => 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400',
                            'ff-chip-blue'   => 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-400',
                            'ff-chip-purple' => 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-400',
                            default          => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
                        };
                        $avatarClass = match ($event['chip']['class']) {
                            'ff-chip-green'  => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                            'ff-chip-blue'   => 'bg-sky-50 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
                            'ff-chip-amber'  => 'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                            'ff-chip-purple' => 'bg-violet-50 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
                            default          => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
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

        {{-- Fund health gauges (2×2 compact) + loan pipeline strip ── --}}
        <div class="flex flex-col gap-3">
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                    <x-heroicon-o-chart-pie class="h-4 w-4 text-emerald-500" />
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Fund health') }}</span>
                </div>
                <div class="grid grid-cols-2 gap-px bg-gray-100 dark:bg-gray-700">
                    @foreach ($d['gauges'] as $gauge)
                        @php
                            $gt = match ($gauge['tone']) {
                                'emerald' => ['ring' => '#1d9e75', 'text' => 'text-emerald-600 dark:text-emerald-400', 'bg' => 'bg-white dark:bg-gray-900'],
                                'amber'   => ['ring' => '#ef9f27', 'text' => 'text-amber-600 dark:text-amber-400',   'bg' => 'bg-white dark:bg-gray-900'],
                                'rose'    => ['ring' => '#e24b4a', 'text' => 'text-red-600 dark:text-red-400',       'bg' => 'bg-white dark:bg-gray-900'],
                                'sky'     => ['ring' => '#0284c7', 'text' => 'text-sky-600 dark:text-sky-400',       'bg' => 'bg-white dark:bg-gray-900'],
                                default   => ['ring' => '#6b7280', 'text' => 'text-gray-500 dark:text-gray-400',     'bg' => 'bg-white dark:bg-gray-900'],
                            };
                            $circumference = 2 * M_PI * 28;
                            $dashOffset = $circumference - ($gauge['percent'] / 100) * $circumference;
                        @endphp
                        <a href="{{ $gauge['url'] }}"
                            class="flex flex-col items-center gap-1 px-3 py-3 transition hover:bg-gray-50 dark:hover:bg-gray-800 {{ $gt['bg'] }}">
                            <div class="relative h-14 w-14">
                                <svg viewBox="0 0 64 64" class="h-full w-full -rotate-90">
                                    <circle cx="32" cy="32" r="28" fill="none" stroke="#e5e7eb" stroke-width="5" class="dark:stroke-gray-700" />
                                    <circle cx="32" cy="32" r="28" fill="none"
                                        stroke="{{ $gt['ring'] }}"
                                        stroke-width="5"
                                        stroke-linecap="round"
                                        stroke-dasharray="{{ $circumference }}"
                                        stroke-dashoffset="{{ $dashOffset }}"
                                        class="transition-all duration-700" />
                                </svg>
                                <span class="absolute inset-0 flex items-center justify-center text-[11px] font-bold tabular-nums {{ $gt['text'] }}">{{ $gauge['value'] }}</span>
                            </div>
                            <p class="text-center text-[10px] font-semibold text-gray-600 dark:text-gray-300">{{ $gauge['label'] }}</p>
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Loan pipeline strip --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                    <x-heroicon-o-funnel class="h-4 w-4 text-sky-500" />
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">{{ __('Loan pipeline') }}</span>
                </div>
                <div class="grid grid-cols-4 divide-x divide-gray-100 dark:divide-gray-700">
                    <a href="{{ $pipeline['queue_needs_decision_url'] ?? '#' }}"
                        class="flex flex-col items-center py-3 transition hover:bg-amber-50/60 dark:hover:bg-amber-950/20">
                        <span class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['needs_decision'] ?? 0 }}</span>
                        <span class="mt-0.5 text-[9px] font-medium text-gray-400">{{ __('Decision') }}</span>
                    </a>
                    <a href="{{ $pipeline['queue_ready_to_disburse_url'] ?? '#' }}"
                        class="flex flex-col items-center py-3 transition hover:bg-sky-50/60 dark:hover:bg-sky-950/20">
                        <span class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['ready_to_disburse'] ?? 0 }}</span>
                        <span class="mt-0.5 text-[9px] font-medium text-gray-400">{{ __('Disburse') }}</span>
                    </a>
                    <a href="{{ $pipeline['loans_active_url'] ?? '#' }}"
                        class="flex flex-col items-center py-3 transition hover:bg-emerald-50/60 dark:hover:bg-emerald-950/20">
                        <span class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['active'] ?? 0 }}</span>
                        <span class="mt-0.5 text-[9px] font-medium text-gray-400">{{ __('Active') }}</span>
                    </a>
                    <a href="{{ $pipeline['loans_completed_url'] ?? '#' }}"
                        class="flex flex-col items-center py-3 transition hover:bg-gray-50 dark:hover:bg-gray-800">
                        <span class="text-xl font-bold tabular-nums text-gray-500 dark:text-gray-300">{{ $pipeline['completed'] ?? 0 }}</span>
                        <span class="mt-0.5 text-[9px] font-medium text-gray-400">{{ __('Closed') }}</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Row 4: Trend charts ── --}}
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        @php
            $maxContrib = max(1, collect($d['contribution_trend'])->max('amount'));
            $maxLoanTrend = max(1, collect($d['loan_trend'])->max('total') ?? 0);
        @endphp
        {{-- Contribution trend --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-chart-bar class="h-4 w-4 text-emerald-500" />
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Contributions posted') }}</h4>
                </div>
                <span class="text-[10px] text-gray-400">{{ __('6 months') }}</span>
            </div>
            <div class="px-4 py-3">
                <div class="flex h-20 items-end gap-1.5">
                    @foreach ($d['contribution_trend'] as $month)
                        @php $barH = max(8, (int) round(($month['amount'] / $maxContrib) * 100)); @endphp
                        <div class="group flex flex-1 flex-col items-center gap-0.5">
                            <span class="text-[10px] font-semibold tabular-nums text-gray-500 opacity-0 transition group-hover:opacity-100">{{ $month['count'] ?: '·' }}</span>
                            <div class="flex w-full max-w-[2rem] items-end justify-center overflow-hidden rounded-t-sm bg-gray-100 dark:bg-gray-700" style="height: 4rem">
                                <div class="w-full rounded-t-sm bg-gradient-to-t from-emerald-500 to-teal-400 transition-all duration-500 group-hover:from-emerald-400"
                                    style="height: {{ $barH }}%"></div>
                            </div>
                            <span class="text-[10px] text-gray-400">{{ $month['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Loan volume chart --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-chart-bar class="h-4 w-4 text-sky-500" />
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Loan volume') }}</h4>
                </div>
                <div class="flex flex-wrap gap-2 text-[10px] text-gray-400">
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
                            <span class="text-[10px] text-gray-400">{{ $month['label'] }}</span>
                        </div>
                    @endforeach
                </div>
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
                                    <x-heroicon-m-chevron-right class="ms-auto h-3 w-3 text-gray-300 dark:text-gray-600" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>

</div>
