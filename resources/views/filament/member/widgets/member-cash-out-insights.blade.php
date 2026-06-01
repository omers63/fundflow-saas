@php
    $d = $this->getData();
@endphp

@if (empty($d))
    <div
        class="rounded-xl border border-dashed border-gray-200 px-4 py-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {{ __('Loading cash-out insights…') }}
    </div>
@else
    <div class="ff-app-insights ff-member-cash-out-insights w-full max-w-none space-y-2.5 mb-1">
        <div class="grid grid-cols-1 gap-2.5 lg:grid-cols-3">
            @include('filament.member.widgets.partials.insights-hero', ['hero' => $d['hero']])

            <div class="lg:col-span-2">
                @include('filament.member.widgets.partials.insights-kpi-strip', [
                    'kpis' => $d['kpis'],
                    'sparkline' => $d['sparkline'],
                    'sparklineMax' => $d['sparkline_max'],
                ])
            </div>
        </div>

        <div
            class="grid grid-cols-2 gap-2 rounded-xl border border-gray-200/80 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:grid-cols-4">
            <div>
                <p class="text-[10px] font-medium uppercase tracking-wide text-gray-500">{{ __('Cash balance') }}</p>
                <p class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $d['availability']['cash_balance'] }}</p>
            </div>
            <div>
                <p class="text-[10px] font-medium uppercase tracking-wide text-gray-500">{{ __('EMI reserved') }}</p>
                <p class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $d['availability']['emi_reserved'] }}</p>
            </div>
            <div>
                <p class="text-[10px] font-medium uppercase tracking-wide text-gray-500">{{ __('Pending withdrawals') }}</p>
                <p class="font-semibold tabular-nums text-amber-700 dark:text-amber-300">{{ $d['availability']['pending_withdrawals'] }}</p>
            </div>
            <div>
                <p class="text-[10px] font-medium uppercase tracking-wide text-gray-500">{{ __('Available to withdraw') }}</p>
                <p class="font-semibold tabular-nums text-teal-700 dark:text-teal-300">{{ $d['availability']['available'] }}</p>
            </div>
        </div>

        @if (count($d['recent']) > 0)
            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Recent cash-outs') }}
                    </h3>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($d['recent'] as $row)
                        <li class="flex justify-between px-3 py-2 text-xs">
                            <span>{{ $row['date'] }} · {{ $row['status_label'] }}</span>
                            <span class="font-semibold tabular-nums">{{ $row['amount'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
