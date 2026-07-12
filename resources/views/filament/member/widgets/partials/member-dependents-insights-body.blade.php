@if (filled($d))
    <div
        @if (isset($insightsVersion))
            wire:key="member-dependents-insights-{{ $insightsVersion }}"
        @endif
        class="ff-app-insights ff-member-dependents-insights w-full max-w-none space-y-2.5 mb-1"
    >
        @include('filament.member.widgets.partials.insights-hero', ['hero' => $d['hero']])

        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            @include('filament.member.widgets.partials.insights-kpi-strip', [
                'kpis' => $d['kpis'],
                'sparkline' => null,
                'sparklineMax' => 1,
            ])
        </div>
    </div>
@else
    <div
        class="mb-1 rounded-xl border border-dashed border-gray-200 px-4 py-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {{ __('Loading household summary…') }}
    </div>
@endif
