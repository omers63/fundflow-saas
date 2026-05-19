<x-filament-panels::page>
    <div class="mx-auto max-w-lg space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Estimate your repayment horizon before applying. This is read-only and does not submit an application.') }}
        </p>

        {{ $this->form }}

        @php
            $preview = $this->getPreview();
        @endphp

        @if ($preview !== [])
            <div @class([
                'ff-app-insights-kpi ff-member-stat-card rounded-xl border px-4 py-4 space-y-3',
                'border-emerald-200/80 dark:border-emerald-500/30' => $preview['eligible'],
                'border-amber-200/80 dark:border-amber-500/30' => ! $preview['eligible'],
            ])
                data-accent="{{ $preview['eligible'] ? 'emerald' : 'amber' }}">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Estimate') }}</h2>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Maximum you may request') }}</dt>
                    <dd class="text-end font-medium tabular-nums">{{ number_format($preview['max'], 2) }} {{ $preview['currency'] }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Loan tier') }}</dt>
                    <dd class="text-end font-medium">{{ $preview['tier'] ?? __('—') }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Monthly installment') }}</dt>
                    <dd class="text-end font-medium tabular-nums">{{ number_format($preview['installment'], 2) }} {{ $preview['currency'] }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Estimated duration') }}</dt>
                    <dd class="text-end font-medium">{{ $preview['months'] > 0 ? trans_choice(':count month|:count months', $preview['months'], ['count' => $preview['months']]) : __('—') }}</dd>
                </dl>
                @unless ($preview['eligible'])
                    <p class="text-xs text-amber-700 dark:text-amber-400">
                        {{ __('Amount or tier is outside your current eligibility. Adjust the amount or contact the fund office.') }}
                    </p>
                @endunless
            </div>
        @endif
    </div>
</x-filament-panels::page>
