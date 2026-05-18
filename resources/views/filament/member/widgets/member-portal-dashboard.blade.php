@php
    $d = $this->getData();
    $quickActionIcon = [
        'emerald' => 'text-emerald-600 dark:text-emerald-400',
        'teal' => 'text-teal-600 dark:text-teal-400',
        'sky' => 'text-sky-600 dark:text-sky-400',
        'indigo' => 'text-indigo-600 dark:text-indigo-400',
        'violet' => 'text-violet-600 dark:text-violet-400',
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

        <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
            @include('filament.member.widgets.partials.insights-hero', ['hero' => $d['hero']])
            @include('filament.member.widgets.partials.insights-kpi-strip', [
                'kpis' => $d['kpis'],
                'sparkline' => $d['sparkline'],
                'sparklineMax' => max(1, max($d['sparkline'])),
            ])
        </div>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($d['quick_actions'] as $action)
                @if ($action['visible'])
                    @php
                        $accent = $action['accent'] ?? 'teal';
                        $iconClass = $quickActionIcon[$accent] ?? 'text-gray-500 dark:text-gray-400';
                    @endphp
                    <a href="{{ $action['url'] }}"
                        class="flex flex-col items-center gap-1.5 rounded-xl border border-gray-200/80 bg-white px-2 py-3 text-center shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:hover:bg-gray-800/80">
                        <x-dynamic-component :component="$action['icon']" @class(['h-5 w-5', $iconClass]) />
                        <span class="text-[10px] font-semibold leading-tight text-gray-700 dark:text-gray-200">{{ $action['label'] }}</span>
                    </a>
                @endif
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            @if ($d['loan_card'])
                <div
                    class="overflow-hidden rounded-xl border border-violet-200/80 bg-gradient-to-br from-violet-50 to-indigo-50/60 shadow-sm dark:border-violet-500/25 dark:from-violet-950/30 dark:to-indigo-950/20">
                    <div class="flex items-center justify-between gap-2 border-b border-violet-100/80 px-3 py-2 dark:border-violet-500/20">
                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-violet-800 dark:text-violet-200">
                            {{ __('Active loan') }}</h3>
                        <a href="{{ $d['loan_card']['view_url'] }}"
                            class="text-[10px] font-medium text-violet-700 hover:underline dark:text-violet-300">{{ __('Details') }} →</a>
                    </div>
                    <div class="space-y-2 px-3 py-2.5">
                        <p class="text-xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $d['loan_card']['outstanding'] }}</p>
                        <div>
                            <div class="mb-0.5 flex justify-between text-[10px] text-gray-500 dark:text-gray-400">
                                <span>{{ __('Repayment schedule') }}</span>
                                <span>{{ $d['loan_card']['installments'] }} · {{ $d['loan_card']['repay_percent'] }}%</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div class="h-full rounded-full bg-gradient-to-r from-violet-500 to-indigo-500"
                                    style="width: {{ max(4, $d['loan_card']['repay_percent']) }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                @php $eligible = $d['eligibility']['eligible']; @endphp
                <div
                    class="overflow-hidden rounded-xl border border-gray-200/80 bg-white px-3 py-2.5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('Loan eligibility') }}
                    </h3>
                    <p @class([
                        'mt-1 text-sm font-semibold',
                        $eligible ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-700 dark:text-gray-300',
                    ])>
                        {{ $eligible ? __('Eligible to apply') : __('Not eligible') }}
                    </p>
                    @if (! $eligible && filled($d['eligibility']['reason'] ?? null))
                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ $d['eligibility']['reason'] }}</p>
                    @endif
                    @if ($eligible)
                        <p class="mt-1 text-[10px] text-gray-500 dark:text-gray-400">{{ __('Up to :amount', ['amount' => $d['eligibility']['max_amount']]) }}</p>
                    @endif
                </div>
            @endif

            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('Open cycle & deposits') }}</h3>
                </div>
                <div class="space-y-2 px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">
                    <div class="flex justify-between">
                        <span>{{ __('Contribution period') }}</span>
                        <span class="font-semibold text-gray-900 dark:text-white">{{ $d['cycle']['period'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>{{ __('Cycle status') }}</span>
                        <span @class([
                            'font-semibold',
                            $d['cycle']['posted'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400',
                        ])>{{ $d['cycle']['posted'] ? __('Posted') : __('Not posted') }}</span>
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($d['recent_deposits'] as $deposit)
                            <li class="flex justify-between py-1.5">
                                <span>{{ $deposit['date'] }} · {{ $deposit['status_label'] }}</span>
                                <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $deposit['amount'] }}</span>
                            </li>
                        @empty
                            <li class="py-2 text-center text-gray-500 dark:text-gray-400">{{ __('No deposits yet') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endif
