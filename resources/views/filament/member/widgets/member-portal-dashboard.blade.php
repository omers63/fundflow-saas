@php
    $d = $this->getData();
    $pollingInterval = method_exists($this, 'getPollingInterval') ? $this->getPollingInterval() : null;
@endphp

@if (empty($d))
    <div
        class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {{ __('Loading your dashboard…') }}
    </div>
@else
    <div class="ff-app-insights ff-member-dashboard w-full max-w-none space-y-3 mb-1" @if (filled($pollingInterval))
    wire:poll.{{ $pollingInterval }} @endif>
        @include('filament.member.widgets.partials.portal-greeting-hero', ['greeting' => $d['greeting']])

        <div>
            <h3 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {{ __('Quick actions') }}
            </h3>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7">
                @foreach ($d['quick_actions'] as $i => $action)
                    @if ($action['visible'])
                        <a href="{{ $action['url'] }}"
                            class="ff-dashboard-action group relative isolate min-h-[5.25rem] overflow-hidden rounded-xl p-3 text-white shadow-sm ring-1 ring-black/10 transition hover:-translate-y-0.5 hover:shadow-lg dark:ring-white/15"
                            style="animation: ff-stat-in 0.4s ease-out {{ 0.04 + ($i * 0.04) }}s forwards">
                            <div @class(['ff-dashboard-action__bg', 'ff-dashboard-action__bg--' . ($action['tone'] ?? 'accounts')])
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
                                @if (filled($action['description'] ?? null))
                                    <span
                                        class="line-clamp-2 text-[10px] leading-snug text-white/95 drop-shadow-sm">{{ $action['description'] }}</span>
                                @endif
                            </div>
                        </a>
                    @endif
                @endforeach
            </div>
        </div>

        @include('filament.member.widgets.partials.portal-lifecycle', ['steps' => $d['steps'] ?? []])

        @include('filament.member.widgets.partials.insights-kpi-strip', [
            'kpis' => $d['kpis'],
            'sparkline' => $d['sparkline'],
            'sparklineMax' => $d['sparkline_max'] ?? max(1, max($d['sparkline'])),
        ])

        @include('filament.member.widgets.partials.portal-arrears', ['arrears' => $d['arrears']])

        @include('filament.member.widgets.partials.portal-relation-summaries', [
            'summaries' => $d['relation_summaries'] ?? [],
        ])

        @include('filament.member.widgets.partials.portal-cycle-loan', [
            'cycle' => $d['cycle'],
            'fundSummary' => $d['fund_summary'],
            'loanCard' => $d['loan_card'],
            'eligibility' => $d['eligibility'],
        ])

            @include('filament.member.widgets.partials.portal-trend-activity', [
                'trend' => $d['trend'] ?? [],
                'recentActivity' => $d['recent_activity'] ?? [],
                'quickLinks' => $d['quick_links'] ?? [],
            ])

            @include('filament.member.widgets.partials.portal-recent-contributions', [
                'contributions' => $d['recent_contributions'] ?? [],
                'deposits' => $d['recent_deposits'] ?? [],
            ])

            @include('filament.member.widgets.partials.portal-statement', ['statement' => $d['latest_statement'] ?? null])

            @include('filament.member.widgets.partials.portal-household', [
                'household' => $d['household'] ?? ['dependents' => [], 'parent_name' => null],
                'profileUrl' => $d['greeting']['profile_url'] ?? null,
                'dependentsUrl' => $d['household']['dependents_url'] ?? null,
            ])
        </div>
@endif
