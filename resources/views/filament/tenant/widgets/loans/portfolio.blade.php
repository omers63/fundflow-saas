@php
    $maxStatus = max(1, collect($d['status_breakdown'])->max('count'));
    $sparkMax = max(1, max($d['sparkline']));
    $pipeline = $d['pipeline'];
    $forecast = $d['forecast'];
    $currency = $d['currency'];
@endphp
    
    @include('filament.tenant.widgets.partials.insights-head', [
        'hero' => $d['hero'],
        'kpis' => $d['kpis'],
        'sparkline' => ($pipeline['needs_decision'] + $pipeline['ready_to_disburse']) > 0 ? $d['sparkline'] : null,
        'sparklineMax' => $sparkMax,
    ])
       
                
                   
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div
            class="rounded-xl border border-sky-200/80 bg-sky-50/60 px-3 py-3 shadow-sm dark:border-sky-800/40 dark:bg-sky-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-300">{{ __('Repayments next 30 days') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-sky-900 dark:text-sky-100">{{ $forecast['next_30_days_count'] }}</p>

                        <p class="mt-1 text-[10px] text-sky-700/80 dark:text-sky-300/80"><x-member::amount :value="$forecast['next_30_days_amount']" :currency="$currency" :precision="0" class="inline" /></p>
        </div>
        <div
            class="rounded-xl border border-amber-200/80 bg-amber-50/60 px-3 py-3 shadow-sm dark:border-amber-800/40 dark:bg-amber-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-300">{{ __('Ready to disburse') }}</p>
            <p class="mt-1 text-xl font-bold tabular-nums text-amber-900 dark:text-amber-100"><x-member::amount :value="$forecast['ready_to_disburse_amount']" :currency="$currency" :precision="0" class="inline" /></p>
            <p class="mt-1 text-[10px] text-amber-700/80 dark:text-amber-300/80">{{ __('Queued approvals') }}</p>
        </div>
        <div
            class="rounded-xl border border-emerald-200/80 bg-emerald-50/60 px-3 py-3 shadow-sm dark:border-emerald-800/40 dark:bg-emerald-950/20">
            <p
                class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-300">{{ __('Fund headroom') }}</p>

                            <p
                class="mt-1 text-xl font-bold tabular-nums text-emerald-900 dark:text-emerald-100"><x-member::amount :value="$forecast['available_fund_headroom']" :currency="$currency" :precision="0" class="inline" /></p>

                               <p
                class="mt-1 text-[10px] text-emerald-700/80 dark:text-emerald-300/80">{{ __('Available before queue') }}</p>

                    
                </div>
        <div class="rounded-xl border px-3 py-3 shadow-sm {{ $forecast['headroom_delta'] < 0 ? 'border-rose-200/80 bg-rose-50/60 dark:border-rose-800/40 dark:bg-rose-950/20' : 'border-violet-200/80 bg-violet-50/60 dark:border-violet-800/40 dark:bg-violet-950/20' }}">
            <p class="text-[10px] font-semibold uppercase tracking-wide {{ $forecast['headroom_delta'] < 0 ? 'text-rose-600 dark:text-rose-300' : 'text-violet-600 dark:text-violet-300' }}">{{ __('Headroom after queue') }}</p>
            <p class="mt-1 text-xl font-bold tabular-nums {{ $forecast['headroom_delta'] < 0 ? 'text-rose-900 dark:text-rose-100' : 'text-violet-900 dark:text-violet-100' }}"><x-member::amount :value="$forecast['headroom_delta']" :currency="$currency" :precision="0" class="inline" /></p>
            
           <p class="mt-1 text-[10px] {{ $forecast['headroom_delta'] < 0 ? 'text-rose-700/80 dark:text-rose-300/80' : 'text-violet-700/80 dark:text-violet-300/80' }}">{{ $forecast['headroom_delta'] < 0 ? __('Queue exceeds current headroom') : __('Queue fits current headroom') }}</p>
        </div>
    </div>

<div class="grid grid-cols-1 gap-3 md:grid-cols-2">
    <div class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-funnel class="h-4 w-4 text-sky-500" />
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Operations pipeline') }}</h3>
            </div>
            @if ($d['emergency_in_queue'] > 0)
                <span class="rounded bg-rose-100 px-1.5 py-0.5 text-[9px] font-bold text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">{{ __(':count emergency', ['count' => $d['emergency_in_queue']]) }}</span>
            @endif
        </div>
        <div class="grid grid-cols-2 divide-x divide-gray-100 dark:divide-gray-700 sm:grid-cols-4">
            @if (($pipeline['pending_eligibility_reviews'] ?? 0) > 0)
                <a href="{{ $pipeline['eligibility_reviews_url'] }}"
                    class="col-span-2 flex items-center justify-between gap-2 border-b border-gray-100 bg-amber-50/70 px-3 py-2 text-start transition hover:bg-amber-100/80 dark:border-gray-700 dark:bg-amber-950/20 dark:hover:bg-amber-950/30 sm:col-span-4">
                    <span class="text-[11px] font-semibold text-amber-800 dark:text-amber-200">
                        {{ trans_choice(':count eligibility review pending|:count eligibility reviews pending', $pipeline['pending_eligibility_reviews'], ['count' => $pipeline['pending_eligibility_reviews']]) }}
                    </span>
                    <span class="text-[10px] font-medium text-amber-700 dark:text-amber-300">{{ __('Review') }} →</span>
                </a>
            @endif
            <a href="{{ $pipeline['queue_needs_decision_url'] }}" class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-amber-50/70 dark:hover:bg-amber-950/20">
                <span class="text-xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ $pipeline['needs_decision'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Decision') }}</span>
            </a>
            <a href="{{ $pipeline['queue_ready_to_disburse_url'] }}" class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-sky-50/70 dark:hover:bg-sky-950/20">
                <span class="text-xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $pipeline['ready_to_disburse'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Disburse') }}</span>
            </a>
            <a href="{{ $pipeline['loans_active_url'] }}" class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-emerald-50/70 dark:hover:bg-emerald-950/20">
                <span class="text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $pipeline['active'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Active') }}</span>
            </a>
            <a href="{{ $pipeline['loans_completed_url'] }}" class="flex flex-col items-center px-2 py-3 text-center transition hover:bg-gray-50/70 dark:hover:bg-gray-900/20">
                <span class="text-xl font-bold tabular-nums text-gray-600 dark:text-gray-300">{{ $pipeline['completed'] }}</span>
                <span class="mt-0.5 text-[10px] text-gray-500">{{ __('Closed') }}</span>
            </a>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="grid grid-cols-1 divide-y divide-gray-100 dark:divide-gray-700 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
            <div class="px-3 py-2.5">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Status mix') }}</p>
                <div class="mt-2 space-y-1.5">
                    @foreach ($d['status_breakdown'] as $row)
                        @php $width = $maxStatus > 0 ? round(($row['count'] / $maxStatus) * 100) : 0; @endphp
                        <div>
                            <div class="mb-0.5 flex justify-between text-[10px]">
                                <span class="text-gray-600 dark:text-gray-300">{{ $row['label'] }}</span>
                                <span class="tabular-nums text-gray-400">{{ $row['count'] }}</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500" style="width: {{ max($row['count'] > 0 ? 6 : 0, $width) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="px-3 py-2.5">
                <div class="flex items-center gap-1">
                    <x-heroicon-o-circle-stack class="h-3.5 w-3.5 text-indigo-500" />
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Fund pool headroom') }}</p>
                </div>
                <div class="mt-2 space-y-1.5">
                    @foreach ($d['fund_utilization'] as $tier)
                        <div>
                            <div class="mb-0.5 flex justify-between text-[10px]">
                                <span class="truncate text-gray-600 dark:text-gray-300">{{ $tier['label'] }}</span>
                                <span class="shrink-0 tabular-nums text-emerald-600">
                                    <x-member::amount :value="$tier['available']" :currency="$currency" :compact="true" class="inline" />
                                </span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div @class(['h-full rounded-full', 'bg-rose-500' => $tier['used_percent'] >= 85, 'bg-amber-500' => $tier['used_percent'] >= 60 && $tier['used_percent'] < 85, 'bg-emerald-500' => $tier['used_percent'] < 60]) style="width: {{ max(4, $tier['used_percent']) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 gap-3 md:grid-cols-2">
    <div class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-queue-list class="h-4 w-4 text-amber-500" />
                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Oldest pending') }}</h4>
            </div>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @forelse ($d['oldest_pending'] as $loan)
                <a href="{{ $loan['view_url'] }}" class="flex items-center gap-2 px-3 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-[10px] font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                        {{ strtoupper(substr($loan['member'], 0, 1)) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-medium text-gray-900 dark:text-white">{{ $loan['member'] }}</p>
                        <p class="truncate text-[10px] text-gray-400">
                            <x-member::amount :value="$loan['amount']" :currency="$currency" :precision="0" class="inline" />
                            @if ($loan['is_emergency']) · <span class="text-rose-500">{{ __('Emergency') }}</span> @endif
                        </p>
                    </div>
                    <span @class([
        'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
        'bg-amber-100 text-amber-800 dark:bg-amber-900/40' => $loan['days_waiting'] <= 7,
        'bg-red-100 text-red-800 dark:bg-red-900/40' => $loan['days_waiting'] > 7,
    ])>{{ $loan['days_waiting'] }}d</span>
                </a>
            @empty
                <div class="px-3 py-6 text-center">
                    <x-heroicon-o-check-circle class="mx-auto h-6 w-6 text-emerald-400" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('No pending applications') }}</p>
                </div>
            @endforelse
        </div>
    </div>

    @include('filament.partials.insights.six-month-workflow-panel', [
    'title' => __('6-month loan volume'),
    'trend' => $d['trend'],
    'primaryLabel' => __('Closed'),
    'secondaryLabel' => __('Decided'),
])
</div>
