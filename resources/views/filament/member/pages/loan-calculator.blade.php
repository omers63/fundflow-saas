<x-filament-panels::page>
    @php
        $currency = $this->currency;
    @endphp

    <div class="space-y-4 sm:space-y-6">
        <div
            class="rounded-xl bg-primary-50 p-4 ring-1 ring-primary-200 dark:bg-primary-900/20 dark:ring-primary-700 sm:p-5">
            <div class="flex items-start gap-3">
                <x-heroicon-o-calculator class="mt-0.5 h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400" />
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-primary-800 dark:text-primary-300">
                        {{ __('Estimate your loan repayment') }}
                    </p>
                    <p class="mt-1 text-sm text-primary-700 dark:text-primary-400">
                        {{ __('Enter an amount to see how many monthly installments you would need to repay it.') }}
                        {{ __('Calculations use your current fund balance (:currency :amount)', [
    'currency' => $currency,
    'amount' => number_format($this->memberFundBalance, 2),
]) }}
                        {{ __('and the active loan tier settings.') }}
                        {{ __('The :percent% settlement threshold is included.', [
    'percent' => round($this->settlementPct * 100),
]) }}
                    </p>
                </div>
            </div>
        </div>

        <div
            class="rounded-xl bg-gradient-to-br from-sky-100 via-white to-indigo-50 p-4 shadow-md ring-1 ring-sky-200/80 dark:from-slate-800 dark:via-sky-950/35 dark:to-indigo-950/30 dark:ring-sky-600/40 sm:p-5">
            <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">
                {{ __('Loan amount') }} ({{ $currency }})
            </label>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                <input type="number" wire:model.live.debounce.400ms="loanAmount" min="0" step="500"
                    placeholder="{{ __('e.g. 20000') }}"
                    class="block w-full rounded-lg border-gray-300 px-4 py-2.5 text-base shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:max-w-xs" />
                @if ($this->loanAmount > 0)
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $currency }} {{ number_format($this->loanAmount, 2) }}
                    </span>
                @endif
            </div>

            @if ($this->activeTiers->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($this->activeTiers as $tier)
                        <button type="button" wire:click="$set('loanAmount', {{ (float) $tier->min_amount }})"
                            class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 transition-colors hover:bg-primary-100 hover:text-primary-700 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-primary-900 dark:hover:text-primary-300">
                            {{ $tier->label }} ({{ $currency }} {{ number_format((float) $tier->min_amount, 0) }})
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($this->loanAmount > 0)
            @if (count($this->calculations) > 0)
                @foreach ($this->calculations as $calc)
                    <div
                        class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/50 sm:px-5 sm:py-4">
                            <div class="min-w-0">
                                <span
                                    class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $calc['tier']->label }}</span>
                                <span
                                    class="ml-2 text-xs text-gray-500 dark:text-gray-400">{{ $this->formatTierRange($calc['tier']) }}</span>
                            </div>
                            <div class="text-right">
                                <span
                                    class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $calc['installments'] }}</span>
                                <span class="ml-1 text-sm text-gray-500 dark:text-gray-400">{{ __('months') }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 px-4 py-4 sm:grid-cols-3 sm:px-5">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('Monthly installment') }}</p>
                                <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white">
                                    {{ $currency }} {{ number_format($calc['min_installment'], 2) }}
                                </p>
                                <p class="text-xs text-gray-400">{{ __('minimum') }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('Your fund portion') }}</p>
                                <p class="mt-1 text-base font-semibold text-emerald-600 dark:text-emerald-400">
                                    {{ $currency }} {{ number_format($calc['member_portion'], 2) }}
                                </p>
                                <p class="text-xs text-gray-400">{{ __('from your fund account') }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('Fund contribution') }}</p>
                                <p class="mt-1 text-base font-semibold text-amber-600 dark:text-amber-400">
                                    {{ $currency }} {{ number_format($calc['master_portion'], 2) }}
                                </p>
                                <p class="text-xs text-gray-400">{{ __('from master fund') }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('Settlement amount') }}</p>
                                <p class="mt-1 text-base font-semibold text-gray-700 dark:text-gray-300">
                                    {{ $currency }} {{ number_format($calc['settlement_amt'], 2) }}
                                </p>
                                <p class="text-xs text-gray-400">
                                    {{ __(':percent% of loan', ['percent' => round($this->settlementPct * 100)]) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('Total to repay') }}</p>
                                <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white">
                                    {{ $currency }} {{ number_format($calc['total_repay'], 2) }}
                                </p>
                                <p class="text-xs text-gray-400">{{ __('master portion + settlement') }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Duration') }}</p>
                                <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white">
                                    {{ __('~:years years', ['years' => number_format($calc['installments'] / 12, 1)]) }}
                                </p>
                                <p class="text-xs text-gray-400">
                                    {{ __(':count monthly payments', ['count' => $calc['installments']]) }}
                                </p>
                            </div>
                        </div>

                        @php
                            $memberPct = $this->loanAmount > 0 ? min(100, $calc['member_portion'] / $this->loanAmount * 100) : 0;
                            $masterPct = 100 - $memberPct;
                        @endphp
                        <div class="px-4 pb-4 sm:px-5">
                            <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Loan funding split') }}</p>
                            <div class="flex h-3 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                @if ($memberPct > 0)
                                    <div class="h-full bg-emerald-500 transition-all" style="width: {{ $memberPct }}%"></div>
                                @endif
                                @if ($masterPct > 0)
                                    <div class="h-full bg-amber-400 transition-all" style="width: {{ $masterPct }}%"></div>
                                @endif
                            </div>
                            <div class="mt-1 flex flex-wrap gap-3 text-xs text-gray-500 dark:text-gray-400">
                                <span class="flex items-center gap-1">
                                    <span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>
                                    {{ __('Your fund (:percent%)', ['percent' => round($memberPct)]) }}
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="inline-block h-2 w-2 rounded-full bg-amber-400"></span>
                                    {{ __('Master fund (:percent%)', ['percent' => round($masterPct)]) }}
                                </span>
                            </div>
                        </div>
                    </div>
                @endforeach

                <p class="mt-2 text-center text-xs text-gray-400 dark:text-gray-500">
                    {{ __('* These are estimates based on current tier settings and your fund balance. Actual terms may vary upon approval.') }}
                </p>
            @else
                <div
                    class="rounded-xl bg-white p-6 text-center shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 sm:p-8">
                    <x-heroicon-o-exclamation-triangle class="mx-auto mb-3 h-10 w-10 text-amber-400" />
                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('No matching loan tier') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __(':currency :amount does not fall within any active loan tier range.', [
                    'currency' => $currency,
                    'amount' => number_format($this->loanAmount, 2),
                ]) }}
                    </p>
                    @if ($this->activeTiers->isNotEmpty())
                        <div class="mt-4 flex flex-wrap justify-center gap-2">
                            @foreach ($this->activeTiers as $tier)
                                <span
                                    class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                                    {{ $tier->label }}: {{ $this->formatTierRange($tier) }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        @else
            <div
                class="rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 sm:p-10">
                <x-heroicon-o-calculator class="mx-auto mb-3 h-12 w-12 text-gray-300 dark:text-gray-600" />
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Enter a loan amount above to see your repayment estimate.') }}
                </p>
            </div>
        @endif
    </div>
</x-filament-panels::page>