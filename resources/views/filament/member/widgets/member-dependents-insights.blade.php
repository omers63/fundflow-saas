@php
    $d = $this->getData();
@endphp

@if (empty($d))
@else
    <div class="ff-app-insights ff-member-dependents-insights w-full max-w-none space-y-2.5 mb-1">
        @include('filament.member.widgets.partials.insights-hero', ['hero' => $d['hero']])

        <div class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            @include('filament.member.widgets.partials.insights-kpi-strip', [
                'kpis' => $d['kpis'],
                'sparkline' => null,
                'sparklineMax' => 1,
            ])
        </div>

        @php $open = $d['open_period']; @endphp
        <div
            class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-indigo-200/70 bg-gradient-to-r from-indigo-50/80 to-violet-50/50 px-3 py-2 dark:border-indigo-500/30 dark:from-indigo-950/30 dark:to-violet-950/20">
            <div class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300">
                <x-heroicon-o-arrow-path class="h-4 w-4 text-indigo-500" />
                <span>
                    <span class="font-semibold text-gray-900 dark:text-white">{{ $open['label'] }}</span>
                    · {{ __('Posted :posted · Pending :pending · Missing :missing', [
                        'posted' => $open['posted'],
                        'pending' => $open['pending'],
                        'missing' => $open['missing'],
                    ]) }}
                </span>
            </div>
            <span class="text-[10px] font-medium text-indigo-700 dark:text-indigo-300">
                {{ trans_choice(':count dependent|:count dependents', $open['total'], ['count' => $open['total']]) }}
            </span>
        </div>
    </div>
@endif
