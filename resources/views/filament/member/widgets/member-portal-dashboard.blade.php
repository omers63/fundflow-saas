@php
    $d = $this->getData();
    $quickActionIcon = [
        'emerald' => 'text-emerald-600 dark:text-emerald-400',
        'teal' => 'text-teal-600 dark:text-teal-400',
        'sky' => 'text-sky-600 dark:text-sky-400',
        'indigo' => 'text-indigo-600 dark:text-indigo-400',
        'violet' => 'text-violet-600 dark:text-violet-400',
        'amber' => 'text-amber-600 dark:text-amber-400',
        'rose' => 'text-rose-600 dark:text-rose-400',
    ];
@endphp

@if (empty($d))
    <div
        class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {{ __('Loading your dashboard…') }}
    </div>
@else
    <div class="ff-app-insights ff-member-dashboard w-full max-w-none space-y-3 mb-1">
        @include('filament.member.widgets.partials.portal-greeting-hero', ['greeting' => $d['greeting']])

        @include('filament.member.widgets.partials.portal-lifecycle', ['steps' => $d['steps'] ?? []])

        <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
            @include('filament.member.widgets.partials.insights-hero', ['hero' => $d['hero']])
            @include('filament.member.widgets.partials.insights-kpi-strip', [
                'kpis' => $d['kpis'],
                'sparkline' => $d['sparkline'],
                'sparklineMax' => $d['sparkline_max'] ?? max(1, max($d['sparkline'])),
            ])
        </div>

        @include('filament.member.widgets.partials.portal-arrears', ['arrears' => $d['arrears']])

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($d['quick_actions'] as $action)
                @if ($action['visible'])
                    @php
                        $accent = $action['accent'] ?? 'teal';
                        $iconClass = $quickActionIcon[$accent] ?? 'text-gray-500 dark:text-gray-400';
                    @endphp
                    <a href="{{ $action['url'] }}"
                        class="ff-member-stat-card flex flex-col items-center gap-1.5 rounded-xl border border-gray-200/80 px-2 py-3 text-center shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-gray-700"
                        data-accent="{{ $accent }}">
                        <x-dynamic-component :component="$action['icon']" @class(['h-5 w-5', $iconClass]) />
                        <span class="text-[10px] font-semibold leading-tight text-gray-700 dark:text-gray-200">{{ $action['label'] }}</span>
                    </a>
                @endif
            @endforeach
        </div>

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
            'trendMax' => $d['trend_max'] ?? 1,
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
