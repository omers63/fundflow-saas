@php
    $d = $this->getData();
@endphp

@if (empty($d))
    <div
        class="rounded-xl border border-dashed border-gray-200 px-4 py-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {{ __('Loading guaranteed-loan insights…') }}
    </div>
@else
    <div class="ff-app-insights ff-member-guaranteed-loan-insights w-full max-w-none space-y-2.5 mb-1">
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
                <p class="text-[10px] font-medium uppercase tracking-wide text-gray-500">{{ __('Outstanding EMIs') }}</p>
                <p class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $d['exposure']['outstanding_emis'] }}</p>
            </div>
            <div>
                <p class="text-[10px] font-medium uppercase tracking-wide text-gray-500">{{ __('Overdue EMIs') }}</p>
                <p @class([
                    'font-semibold tabular-nums',
                    (int) $d['exposure']['overdue_emis'] > 0
                        ? 'text-amber-700 dark:text-amber-300'
                        : 'text-gray-900 dark:text-white',
                ])>{{ $d['exposure']['overdue_emis'] }}</p>
            </div>
            <div>
                <p class="text-[10px] font-medium uppercase tracking-wide text-gray-500">{{ __('Liability on you') }}</p>
                <p @class([
                    'font-semibold tabular-nums',
                    (int) $d['exposure']['liability_on_you'] > 0
                        ? 'text-rose-700 dark:text-rose-300'
                        : 'text-gray-900 dark:text-white',
                ])>{{ $d['exposure']['liability_on_you'] }}</p>
            </div>
            <div>
                <p class="text-[10px] font-medium uppercase tracking-wide text-gray-500">{{ __('At default risk') }}</p>
                <p @class([
                    'font-semibold tabular-nums',
                    (int) $d['exposure']['at_risk_loans'] > 0
                        ? 'text-rose-700 dark:text-rose-300'
                        : 'text-gray-900 dark:text-white',
                ])>{{ $d['exposure']['at_risk_loans'] }}</p>
            </div>
        </div>

        @if (count($d['recent']) > 0)
            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Recent guaranteed loans') }}
                    </h3>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($d['recent'] as $row)
                        <li>
                            <a href="{{ $row['view_url'] }}"
                                class="flex justify-between px-3 py-2 text-xs transition hover:bg-gray-50 dark:hover:bg-white/5">
                                <span class="min-w-0 truncate pe-2">
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $row['borrower'] }}</span>
                                    · {{ $row['status_label'] }} · {{ $row['liability_label'] }}
                                </span>
                                <x-ff-money-text :text="$row['amount']" class="shrink-0 font-semibold tabular-nums" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
