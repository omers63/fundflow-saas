<x-filament-panels::page>
    @php
        $currency = $this->currency;
        $loanAmount = (float) ($this->loanAmount ?? 0);
        $eligibility = $this->eligibility;
        $eligible = (bool) ($eligibility['eligible'] ?? false);
        $fundBalance = (float) ($this->memberFundBalance);
        $projection = $this->projection;
        $projectedFund = (float) ($projection['projected_fund'] ?? $fundBalance);
        $settlementPercent = round($this->settlementPct * 100);
        $eligibilityPercent = round($this->eligibilityPct * 100);
        $maxLoanFormatted = \App\Filament\Support\MoneyDisplay::html((float) ($eligibility['max_loan_amount'] ?? 0), $currency, precision: 0)?->toHtml() ?? e('—');
        $moneyHtml = fn(float|int|string|null $value, int $precision = 2): string => \App\Filament\Support\MoneyDisplay::html($value, $currency, precision: $precision)?->toHtml() ?? e('—');
        $hasCurrentLoan = $this->hasCurrentLoanToSettle;
        $activeLoan = $this->activeLoan;
    @endphp

    <div class="ff-member-loan-calculator space-y-4 sm:space-y-5">
        {{-- Top Stats Cards --}}
        <div class="grid grid-cols-1 gap-3 {{ $hasCurrentLoan ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
            <a href="{{ $this->fundAccountUrl }}" class="ff-member-stat-card ff-member-loan-calc-status" data-accent="teal">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-circle-stack class="h-4 w-4 shrink-0 text-teal-600 dark:text-teal-400" />
                    <p class="ff-member-stat-card__label !mb-0">{{ __('Fund balance') }}</p>
                </div>
                <p @class([
                    'mt-2 text-xl font-bold tabular-nums text-gray-900 dark:text-white sm:text-2xl',
                    'ff-member-amount--danger text-rose-600 dark:text-rose-400' => $fundBalance < 0,
                ])>
                    <x-member::amount :value="$fundBalance" :currency="$currency" />
                </p>
                <p class="ff-member-stat-card__hint !whitespace-normal">{{ __('Your fund account') }}</p>
            </a>

            <div
                class="ff-member-stat-card ff-member-loan-calc-status"
                data-accent="{{ $eligible ? 'emerald' : 'amber' }}"
            >
                <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                        @if ($eligible)
                            <x-heroicon-o-check-badge class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        @else
                            <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                        @endif
                        <p class="ff-member-stat-card__label !mb-0">{{ __('Loan eligibility') }}</p>
                    </div>
                    <x-member::chip :variant="$eligible ? 'green' : 'amber'">
                        {{ $eligible ? __('Eligible to apply') : __('Not eligible') }}
                    </x-member::chip>
                </div>
                @if ($eligible)
                    <p class="mt-2 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                        {!! __('Up to :amount', ['amount' => $maxLoanFormatted]) !!}
                    </p>
                @elseif (filled($eligibility['reason'] ?? null))
                    <p class="mt-2 text-sm leading-5 text-gray-700 dark:text-gray-300">
                        {{ $eligibility['reason'] }}
                    </p>
                @endif
            </div>

            @if ($hasCurrentLoan)
                <div class="ff-member-stat-card ff-member-loan-calc-status" data-accent="sky">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-sparkles class="h-4 w-4 shrink-0 text-sky-600 dark:text-sky-400" />
                            <p class="ff-member-stat-card__label !mb-0">{{ __('Projected fund at start') }}</p>
                        </div>
                        <x-member::chip variant="blue">
                            {{ $this->currentCycleLabel }}
                        </x-member::chip>
                    </div>
                    <p @class([
                        'mt-2 text-xl font-bold tabular-nums text-gray-900 dark:text-white sm:text-2xl',
                        'ff-member-amount--danger text-rose-600 dark:text-rose-400' => $projectedFund < 0,
                    ])>
                        <x-member::amount :value="$projectedFund" :currency="$currency" />
                    </p>
                    <p class="ff-member-stat-card__hint !whitespace-normal">
                        {!! __('Cash needed: :amount', ['amount' => $moneyHtml($projection['cash_needed'] ?? 0)]) !!}
                    </p>
                </div>
            @endif
        </div>

        {{-- 3-Tab Bar --}}
        <div class="ff-member-tab-bar flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-700" role="tablist" aria-label="{{ __('Loan calculator tabs') }}">
            @if ($hasCurrentLoan)
                <button
                    type="button"
                    wire:click="setActiveTab('settlement')"
                    @class([
                        'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold transition shadow-sm',
                        'bg-primary-600 text-white' => $this->activeTab === 'settlement',
                        'bg-white text-gray-700 hover:bg-gray-50 border border-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-700 dark:hover:bg-gray-700' => $this->activeTab !== 'settlement',
                    ])
                >
                    <x-heroicon-o-arrow-path-rounded-square class="h-4 w-4 shrink-0" />
                    <span>{{ __('Outstanding loan settlement') }}</span>
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/60 dark:text-amber-200">
                        {{ __('Active loan') }}
                    </span>
                </button>
            @endif

            <button
                type="button"
                wire:click="setActiveTab('estimate')"
                @class([
                    'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold transition shadow-sm',
                    'bg-primary-600 text-white' => $this->activeTab === 'estimate',
                    'bg-white text-gray-700 hover:bg-gray-50 border border-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-700 dark:hover:bg-gray-700' => $this->activeTab !== 'estimate',
                ])
            >
                <x-heroicon-o-calculator class="h-4 w-4 shrink-0" />
                <span>{{ __('Loan estimation') }}</span>
            </button>

            <button
                type="button"
                wire:click="setActiveTab('simulate')"
                @class([
                    'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold transition shadow-sm',
                    'bg-primary-600 text-white' => $this->activeTab === 'simulate',
                    'bg-white text-gray-700 hover:bg-gray-50 border border-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-700 dark:hover:bg-gray-700' => $this->activeTab !== 'simulate',
                ])
            >
                <x-heroicon-o-chart-bar-square class="h-4 w-4 shrink-0" />
                <span>{{ __('Lifecycle simulator') }}</span>
                @if (count($this->calculations) > 0)
                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200">
                        {{ __('Ready') }}
                    </span>
                @endif
            </button>
        </div>

        {{-- TAB 1: OUTSTANDING LOAN SETTLEMENT --}}
        @if ($hasCurrentLoan && $this->activeTab === 'settlement')
                            <div class="space-y-4">
                                @if ($activeLoan !== null)
                                    @php
                                        $unpaidInstallments = $activeLoan->installments->whereIn('status', ['pending', 'overdue']);
                                        $remainingCount = $unpaidInstallments->count();
                                        $regularInstallmentAmount = (float) ($unpaidInstallments->first()?->amount ?? $activeLoan->loanTier?->min_monthly_installment ?? 0);
                                        $totalApproved = (float) ($activeLoan->amount_approved ?: $activeLoan->amount_requested ?: $activeLoan->amount);
                                    @endphp
                                    <div class="rounded-xl border border-gray-200 bg-gradient-to-br from-amber-50/50 via-white to-slate-50/50 p-4 shadow-sm dark:border-gray-700 dark:from-gray-800/80 dark:via-gray-800 dark:to-gray-850">
                                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-3 dark:border-gray-700">
                                            <div class="flex items-center gap-2">
                                                <x-heroicon-o-document-text class="h-5 w-5 text-amber-600 dark:text-amber-400" />
                                                <h3 class="text-sm font-bold text-gray-900 dark:text-white">
                                                    {{ __('Active loan #:id', ['id' => $activeLoan->id]) }}
                                                </h3>
                                                @if ($activeLoan->loanTier)
                                                    <x-member::chip variant="blue">{{ $activeLoan->loanTier->label }}</x-member::chip>
                                                @endif
                                            </div>
                                            <x-member::chip variant="amber">{{ __('Active repayment') }}</x-member::chip>
                                        </div>
                                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                            <div>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Loan amount') }}</p>
                                                <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">
                                                    <x-member::amount :value="$totalApproved" :currency="$currency" />
                                                </p>
                                            </div>
                                            <div>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Remaining installments') }}</p>
                                                <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">
                                                    {{ $remainingCount }}
                                                </p>
                                            </div>
                                            <div>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Monthly installment') }}</p>
                                                <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">
                                                    <x-member::amount :value="$regularInstallmentAmount" :currency="$currency" />
                                                </p>
                                            </div>
                                            <div>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Current fund deficit') }}</p>
                                                <p class="text-sm font-semibold tabular-nums text-rose-600 dark:text-rose-400">
                                                    <x-member::amount :value="$fundBalance" :currency="$currency" />
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                <x-member::panel :title="__('Settlement method & options')">
                                    <div class="space-y-4">
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                {{ __('Settle current loan') }}
                                            </label>
                                            <div class="space-y-2">
                                                @foreach ($this->currentLoanSettlementOptions as $value => $label)
                                                    <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                                                        <input
                                                            type="radio"
                                                            wire:model.live="currentLoanSettlement"
                                                            value="{{ $value }}"
                                                            class="mt-1 border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700"
                                                        />
                                                        <span>{{ $label }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                                {{ __('Regular payments use one remaining installment per cycle. Partial early settlement pays remaining installments now. Full early settlement closes the loan and restores your pre-loan fund. Later cycles then add your projected monthly contribution.') }}
                                            </p>
                                            @if ($this->showsCurrentLoanThresholdOptions)
                                                <div class="mt-4 space-y-3 rounded-lg bg-gray-50 p-3 ring-1 ring-gray-200/80 dark:bg-gray-800/50 dark:ring-gray-700">
                                                    <div>
                                                        <label class="flex cursor-pointer items-start gap-2 text-sm font-medium text-gray-800 dark:text-gray-200">
                                                            <input
                                                                id="loan-calculator-include-settlement-threshold"
                                                                type="checkbox"
                                                                wire:model.live="includeSettlementThreshold"
                                                                class="mt-0.5 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700"
                                                            />
                                                            <span>{{ __('Include the :percent% settlement threshold', ['percent' => $settlementPercent]) }}</span>
                                                        </label>
                                                        <p class="ms-6 text-xs text-gray-500 dark:text-gray-400">
                                                            {{ __('Added to the projected fund at start.') }}
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <label class="flex cursor-pointer items-start gap-2 text-sm font-medium text-gray-800 dark:text-gray-200">
                                                            <input
                                                                id="loan-calculator-include-eligibility-threshold"
                                                                type="checkbox"
                                                                wire:model.live="includeEligibilityThreshold"
                                                                class="mt-0.5 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700"
                                                            />
                                                            <span>{{ __('Include the :percent% eligibility threshold', ['percent' => $eligibilityPercent]) }}</span>
                                                        </label>
                                                        <p class="ms-6 text-xs text-gray-500 dark:text-gray-400">
                                                            {{ __('Moves the projected start date forward until the projected fund meets this threshold. Turning it off restores the date you chose.') }}
                                                        </p>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>

                                        <div class="grid grid-cols-1 gap-4 border-t border-gray-100 pt-4 sm:grid-cols-2 dark:border-gray-700">
                                            <div>
                                                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300" for="loan-calculator-start-date-tab1">
                                                    {{ __('Projected start date') }}
                                                </label>
                                                <input
                                                    id="loan-calculator-start-date-tab1"
                                                    type="date"
                                                    wire:model.live="startDate"
                                                    class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                                />
                                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                    {{ __('Treat this as the disbursement date. The schedule uses the contribution cycle that contains this date (:cycle).', [
                'cycle' => $this->currentCycleLabel,
            ]) }}
                                                </p>
                                                @if (filled($this->startDateAdjustmentMessage()))
                                                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                                        {{ $this->startDateAdjustmentMessage() }}
                                                    </p>
                                                @endif
                                            </div>
                                            <div>
                                                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300" for="loan-calculator-projected-contribution-tab1">
                                                    {{ __('Projected monthly contribution') }}
                                                </label>
                                                <select
                                                    id="loan-calculator-projected-contribution-tab1"
                                                    wire:model.live="projectedContributionAmount"
                                                    class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                                >
                                                    @foreach ($this->projectedContributionOptions as $value => $label)
                                                        <option value="{{ $value }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                    {{ __('Used for contribution cycles until the start date. Defaults to your current contribution amount.') }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </x-member::panel>

                                {{-- Live Projected Fund & Cash Impact Card --}}
                                @include('filament.member.pages.partials.loan-calculator-projected-fund-card', [
                                    'projection' => $projection,
                                    'currency' => $currency,
                                    'moneyHtml' => $moneyHtml,
                                    'settlementPercent' => $settlementPercent,
                                ])

                                <div class="flex justify-end pt-2">
                                    <x-filament::button
                                        type="button"
                                        color="primary"
                                        size="md"
                                        icon="heroicon-o-arrow-right"
                                        icon-position="after"
                                        wire:click="setActiveTab('estimate')"
                                    >
                                        {{ __('Proceed to loan estimation') }}
                                    </x-filament::button>
                                </div>
                            </div>
        @endif

        {{-- TAB 2: LOAN ESTIMATION --}}
        @if ($this->activeTab === 'estimate')
                            <div class="space-y-4">
                                @if ($hasCurrentLoan)
                                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sky-200 bg-sky-50/70 px-4 py-3 dark:border-sky-800 dark:bg-sky-950/30">
                                        <div class="flex items-center gap-2">
                                            <x-heroicon-o-information-circle class="h-5 w-5 text-sky-600 dark:text-sky-400 shrink-0" />
                                            <div class="text-xs text-sky-900 dark:text-sky-200">
                                                <span class="font-semibold">{{ __('Starting fund at loan date:') }}</span>
                                                <span class="font-bold tabular-nums"><x-member::amount :value="$projectedFund" :currency="$currency" /></span>
                                                <span class="text-sky-700 dark:text-sky-300">({{ __('Disbursement: :date', ['date' => $this->startDate]) }})</span>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="setActiveTab('settlement')"
                                            class="text-xs font-semibold text-primary-700 underline hover:text-primary-800 dark:text-primary-300 dark:hover:text-primary-200"
                                        >
                                            {{ __('Modify settlement options') }} &rarr;
                                        </button>
                                    </div>
                                @endif

                                <x-member::panel :title="__('How this estimate works')" collapsible :open="false">
                                    <p class="text-sm text-gray-600 dark:text-gray-300">
                                        {{ __('Enter a loan amount to see your fund share, the master fund share, monthly installments, and the two thresholds that apply.') }}
                                    </p>
                                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div class="ff-member-loan-calc-explain ff-member-loan-calc-explain--settlement">
                                            <div class="flex items-center gap-2">
                                                <x-member::chip variant="blue">{{ __('Settlement threshold') }}</x-member::chip>
                                                <span class="text-xs font-semibold tabular-nums text-sky-800 dark:text-sky-200">{{ $settlementPercent }}%</span>
                                            </div>
                                            <p class="mt-2 text-sm leading-5 text-gray-700 dark:text-gray-300">
                                                {{ __('An extra :percent% of the loan amount is added to the master fund portion. That combined amount is what you repay through monthly installments.', [
                'percent' => $settlementPercent,
            ]) }}
                                            </p>
                                        </div>
                                        <div class="ff-member-loan-calc-explain ff-member-loan-calc-explain--eligibility">
                                            <div class="flex items-center gap-2">
                                                <x-member::chip variant="purple">{{ __('Eligibility threshold') }}</x-member::chip>
                                                <span class="text-xs font-semibold tabular-nums text-violet-800 dark:text-violet-200">{{ $eligibilityPercent }}%</span>
                                            </div>
                                            <p class="mt-2 text-sm leading-5 text-gray-700 dark:text-gray-300">
                                                {{ __('After the loan is fully repaid, your fund balance must reach :percent% of the matching loan tier ceiling before you can apply again. This is not added to your installments.', [
                'percent' => $eligibilityPercent,
            ]) }}
                                            </p>
                                        </div>
                                    </div>
                                </x-member::panel>

                                <x-member::panel :title="__('Loan parameters')">
                                    <div class="space-y-4">
                                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <div>
                                                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300" for="loan-calculator-start-date-tab2">
                                                    {{ __('Projected start date') }}
                                                </label>
                                                <input
                                                    id="loan-calculator-start-date-tab2"
                                                    type="date"
                                                    wire:model.live="startDate"
                                                    class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                                />
                                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                    {{ __('Treat this as the disbursement date. The schedule uses the contribution cycle that contains this date (:cycle).', [
                'cycle' => $this->currentCycleLabel,
            ]) }}
                                                </p>
                                                @if (filled($this->startDateAdjustmentMessage()))
                                                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                                        {{ $this->startDateAdjustmentMessage() }}
                                                    </p>
                                                @endif
                                            </div>
                                            <div>
                                                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300" for="loan-calculator-projected-contribution-tab2">
                                                    {{ __('Projected monthly contribution') }}
                                                </label>
                                                <select
                                                    id="loan-calculator-projected-contribution-tab2"
                                                    wire:model.live="projectedContributionAmount"
                                                    class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                                >
                                                    @foreach ($this->projectedContributionOptions as $value => $label)
                                                        <option value="{{ $value }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                    {{ __('Used for contribution cycles until the start date. Defaults to your current contribution amount.') }}
                                                </p>
                                            </div>
                                        </div>

                                        {{-- Projected Fund & Cash Preview on Tab 2 --}}
                                        @include('filament.member.pages.partials.loan-calculator-projected-fund-card', [
                                            'projection' => $projection,
                                            'currency' => $currency,
                                            'moneyHtml' => $moneyHtml,
                                            'settlementPercent' => $settlementPercent,
                                        ])

                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                {{ __('Funding approach') }}
                                            </label>
                                            @if (count($this->fundingApproachOptions) > 1)
                                                <div class="space-y-2">
                                                    @foreach ($this->fundingApproachOptions as $value => $label)
                                                        <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                                                            <input
                                                                type="radio"
                                                                wire:model.live="fundingApproach"
                                                                value="{{ $value }}"
                                                                class="mt-1 border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700"
                                                            />
                                                            <span>{{ $label }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            @else
                                                <p class="text-sm text-gray-700 dark:text-gray-300">
                                                    {{ $this->fundingApproachOptions[$this->fundingApproach] ?? '—' }}
                                                </p>
                                            @endif
                                        </div>

                                        @if ($this->usesConfiguredSplit)
                                                                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                                                                {{ __('If you choose a configured fund split, :member% comes from your fund and :master% from the master fund.', [
                                                'member' => number_format($this->memberFundingSplitPercent, 1),
                                                'master' => number_format($this->masterFundingSplitPercent, 1),
                                            ]) }}
                                                                                            </p>
                                        @endif

                                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:items-end">
                                            <div>
                                                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300" for="loan-calculator-grace">
                                                    {{ __('Grace cycles before first repayment') }}
                                                </label>
                                                <select
                                                    id="loan-calculator-grace"
                                                    wire:model.live="graceCycles"
                                                    class="block w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                                >
                                                    @foreach ($this->graceCycleOptions as $value => $label)
                                                        <option value="{{ $value }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            <div>
                                                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    {{ __('Loan amount') }}
                                                    (<span dir="ltr">{!! \App\Filament\Support\MoneyDisplay::symbolHtml($currency)->toHtml() !!}</span>)
                                                </label>
                                                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                                                    <input
                                                        type="text"
                                                        inputmode="decimal"
                                                        autocomplete="off"
                                                        wire:model="loanAmount"
                                                        wire:keydown.enter.prevent="calculate"
                                                        placeholder="{{ __('e.g. 20000') }}"
                                                        class="block w-full min-w-0 flex-1 rounded-lg border-gray-300 px-4 py-2.5 text-base shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                                    />
                                                    <x-filament::button type="button" color="primary" size="sm" icon="heroicon-o-calculator" class="w-full sm:w-auto" wire:click="calculate">
                                                        {{ __('Calculate') }}
                                                    </x-filament::button>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('Grace skips EMI and the contribution for those cycles. If the start-cycle contribution is already paid or included in the projection, grace starts on the next unpaid cycle.') }}
                                        </p>
                                    </div>
                                </x-member::panel>

                                {{-- Excess Fund & Disposition Preview --}}
                                @php
                                    $previewCalc = ($this->calculations[0] ?? null);
                                    $previewExcess = is_array($previewCalc) ? (float) ($previewCalc['excess_fund'] ?? 0) : 0.0;
                                    $previewEarlySettlement = is_array($previewCalc) ? (float) ($previewCalc['early_settlement_amount'] ?? 0) : 0.0;
                                    $previewInstallmentsCovered = is_array($previewCalc) ? (int) ($previewCalc['installments_covered'] ?? 0) : 0;
                                    $showPreviewExcess = $this->usesConfiguredSplit && $previewExcess > 0.00001;
                                    $showPreviewDisposition = $showPreviewExcess && (
                                        ($this->showsEarlySettlementEstimate && $previewEarlySettlement > 0.00001)
                                        || $this->showsCashTransferEstimate
                                    );
                                @endphp
                                @if ($showPreviewExcess || $showPreviewDisposition)
                                    <div class="grid grid-cols-1 gap-3 {{ $showPreviewDisposition ? 'sm:grid-cols-2' : 'sm:grid-cols-1' }}">
                                        @if ($showPreviewExcess)
                                            <div class="rounded-xl border border-amber-200 bg-amber-50/80 p-4 shadow-sm dark:border-amber-800 dark:bg-amber-950/30">
                                                <p class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">
                                                    {{ __('Excess fund above share') }}
                                                </p>
                                                <p class="mt-1 text-lg font-bold tabular-nums text-amber-900 dark:text-amber-100">
                                                    <x-member::amount :value="$previewExcess" :currency="$currency" />
                                                </p>
                                            </div>
                                        @endif

                                        @if ($showPreviewDisposition)
                                            <div class="rounded-xl border border-sky-200 bg-sky-50/80 p-4 shadow-sm dark:border-sky-800 dark:bg-sky-950/30">
                                                @if ($this->showsEarlySettlementEstimate && $previewEarlySettlement > 0.00001)
                                                    <p class="text-xs uppercase tracking-wide text-sky-700 dark:text-sky-300">
                                                        {{ __('Estimated early settlement') }}
                                                    </p>
                                                    <p class="mt-1 text-lg font-bold tabular-nums text-sky-900 dark:text-sky-100">
                                                        <x-member::amount :value="$previewEarlySettlement" :currency="$currency" />
                                                    </p>
                                                    <p class="mt-0.5 text-xs text-sky-700/80 dark:text-sky-300/80">
                                                        {{ __('~:count installment(s)', ['count' => $previewInstallmentsCovered]) }}
                                                    </p>
                                                @else
                                                    <p class="text-xs uppercase tracking-wide text-sky-700 dark:text-sky-300">
                                                        {{ __('Estimated cash transfer') }}
                                                    </p>
                                                    <p class="mt-1 text-lg font-bold tabular-nums text-sky-900 dark:text-sky-100">
                                                        <x-member::amount :value="$previewExcess" :currency="$currency" />
                                                    </p>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endif

                                {{-- Block Reason --}}
                                @if ($loanAmount > 0 && filled($this->estimateBlockReason))
                                    <div class="rounded-xl bg-white p-6 text-center shadow-sm ring-1 ring-amber-200 dark:bg-gray-800 dark:ring-amber-800/50 sm:p-8">
                                        <x-heroicon-o-exclamation-triangle class="mx-auto mb-3 h-10 w-10 text-amber-400" />
                                        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('Cannot estimate or simulate this loan') }}</p>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                            {{ $this->estimateBlockReason }}
                                        </p>
                                    </div>
                                @endif

                                {{-- Tier Calculation Cards --}}
                                @if ($loanAmount > 0 && empty($this->estimateBlockReason) && count($this->calculations) > 0)
                                    <div class="space-y-4">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <h3 class="text-base font-bold text-gray-900 dark:text-white">
                                                {{ __('Calculation breakdown') }}
                                            </h3>
                                            <x-filament::button
                                                type="button"
                                                color="primary"
                                                size="sm"
                                                icon="heroicon-o-chart-bar-square"
                                                wire:click="setCalculatorMode('simulate')"
                                            >
                                                {{ __('Simulate loan lifecycle') }} &rarr;
                                            </x-filament::button>
                                        </div>

                                        @foreach ($this->calculations as $calc)
                                            @php
                                                $memberPct = $loanAmount > 0 ? min(100, $calc['member_portion'] / $loanAmount * 100) : 0;
                                                $masterPct = 100 - $memberPct;
                                                $minInstallment = (float) $calc['min_installment'];
                                                $installments = (int) $calc['installments'];
                                                $settlementAmt = (float) $calc['settlement_amt'];
                                                $totalRepay = (float) $calc['total_repay'];
                                                $eligibilityAmt = (float) $calc['eligibility_amt'];
                                                $eligibilityBase = (float) $calc['eligibility_base'];
                                                $excessFund = (float) ($calc['excess_fund'] ?? 0);
                                                $earlySettlement = (float) ($calc['early_settlement_amount'] ?? 0);
                                                $installmentsCovered = (int) ($calc['installments_covered'] ?? 0);
                                                $remainingPaymentMonths = $calc['remaining_payment_months'] ?? null;
                                                $durationMonths = (int) ($calc['duration_months'] ?? 0);
                                                $isShortened = $durationMonths > 0 && $durationMonths < $installments;
                                                $schedule = (array) ($calc['schedule'] ?? []);
                                                $scheduleRows = (array) ($schedule['rows'] ?? []);
                                                $activeCyclesCount = count(array_filter($scheduleRows, fn(array $r): bool => ($r['kind'] ?? '') !== 'dropped'));
                                                $tier = $calc['tier'];
                                            @endphp

                                            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-5">
                                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-3 dark:border-gray-700">
                                                    <div class="flex items-center gap-2">
                                                        <h4 class="text-base font-bold text-gray-900 dark:text-white">
                                                            {{ $tier->label }}
                                                        </h4>
                                                        <span class="text-xs text-gray-500 dark:text-gray-400">
                                                            ({{ $this->formatTierRange($tier) }})
                                                        </span>
                                                    </div>
                                                    <x-member::chip variant="blue">
                                                        @if ($isShortened && $remainingPaymentMonths !== null)
                                                            {{ __(':duration months (:payable payable)', ['duration' => $durationMonths, 'payable' => $remainingPaymentMonths]) }}
                                                        @else
                                                            {{ __(':count installments', ['count' => $installments]) }}
                                                        @endif
                                                    </x-member::chip>
                                                </div>

                                                {{-- Summary Pills --}}
                                                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                                                    <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-1 font-medium text-slate-700 dark:bg-slate-700/60 dark:text-slate-300">
                                                        {{ __('Your fund now') }}: <span class="ms-1 font-bold"><x-member::amount :value="$fundBalance" :currency="$currency" /></span>
                                                    </span>
                                                    <span class="inline-flex items-center rounded-md bg-emerald-100 px-2 py-1 font-medium text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300">
                                                        {{ __('This loan') }}: <span class="ms-1 font-bold"><x-member::amount :value="$loanAmount" :currency="$currency" /></span>
                                                    </span>
                                                    <span class="inline-flex items-center rounded-md bg-purple-100 px-2 py-1 font-medium text-purple-800 dark:bg-purple-950/50 dark:text-purple-300">
                                                        {{ __('After this loan') }}: <span class="ms-1 font-bold"><x-member::amount :value="$eligibilityAmt" :currency="$currency" /></span>
                                                    </span>
                                                </div>

                                                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                                    {{-- Loan Share --}}
                                                    <div class="rounded-lg bg-gray-50 p-3 ring-1 ring-gray-100 dark:bg-gray-900/40 dark:ring-gray-800">
                                                        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                                            {{ __('Member share') }}
                                                        </p>
                                                        <p class="mt-1 text-base font-bold tabular-nums text-gray-900 dark:text-white">
                                                            <x-member::amount :value="$calc['member_portion']" :currency="$currency" />
                                                        </p>
                                                        <div class="mt-2 h-1.5 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                                                            <div class="h-1.5 rounded-full bg-emerald-500" style="width: {{ $memberPct }}%"></div>
                                                        </div>
                                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                            {{ number_format($memberPct, 1) }}% {{ __('of loan') }}
                                                        </p>
                                                    </div>

                                                    {{-- Master Portion --}}
                                                    <div class="rounded-lg bg-gray-50 p-3 ring-1 ring-gray-100 dark:bg-gray-900/40 dark:ring-gray-800">
                                                        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                                            {{ __('Master portion') }}
                                                        </p>
                                                        <p class="mt-1 text-base font-bold tabular-nums text-gray-900 dark:text-white">
                                                            <x-member::amount :value="$calc['master_portion']" :currency="$currency" />
                                                        </p>
                                                        <div class="mt-2 h-1.5 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                                                            <div class="h-1.5 rounded-full bg-sky-500" style="width: {{ $masterPct }}%"></div>
                                                        </div>
                                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                            {{ number_format($masterPct, 1) }}% {{ __('of loan') }}
                                                        </p>
                                                    </div>

                                                    {{-- Monthly Repayment --}}
                                                    <div class="rounded-lg bg-gray-50 p-3 ring-1 ring-gray-100 dark:bg-gray-900/40 dark:ring-gray-800">
                                                        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                                            {{ __('Monthly installment') }}
                                                        </p>
                                                        <p class="mt-1 text-base font-bold tabular-nums text-primary-600 dark:text-primary-400">
                                                            <x-member::amount :value="$minInstallment" :currency="$currency" />
                                                        </p>
                                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                            <span class="font-semibold">{{ __('Duration') }}:</span>
                                                            @if ($isShortened)
                                                                {{ $durationMonths }} {{ __('months') }}
                                                                <span class="text-emerald-600 dark:text-emerald-400">({{ __('shortened from :count', ['count' => $installments]) }})</span>
                                                            @else
                                                                {{ $installments }} {{ __('months') }}
                                                            @endif
                                                        </p>
                                                    </div>

                                                    {{-- Total Repayment --}}
                                                    <div class="rounded-lg bg-gray-50 p-3 ring-1 ring-gray-100 dark:bg-gray-900/40 dark:ring-gray-800">
                                                        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                                                            {{ __('Total to repay') }}
                                                        </p>
                                                        <p class="mt-1 text-base font-bold tabular-nums text-gray-900 dark:text-white">
                                                            <x-member::amount :value="$totalRepay" :currency="$currency" />
                                                        </p>
                                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                            {{ __('Settlement amount') }}: {!! $moneyHtml($settlementAmt) !!}
                                                        </p>
                                                    </div>
                                                </div>

                                                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 border-t border-gray-100 pt-3 dark:border-gray-700">
                                                    <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                                                        <span>{{ __('Settlement threshold (:percent%)', ['percent' => $settlementPercent]) }}</span>
                                                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">
                                                            <x-member::amount :value="$settlementAmt" :currency="$currency" />
                                                        </span>
                                                    </div>
                                                    <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                                                        <span>{{ __('Eligibility threshold amount') }} ({{ $eligibilityPercent }}% {{ __('of tier ceiling (:ceiling)', ['ceiling' => \App\Filament\Support\MoneyDisplay::format($eligibilityBase, $currency, precision: 0) ?? (string) $eligibilityBase]) }})</span>
                                                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">
                                                            <x-member::amount :value="$eligibilityAmt" :currency="$currency" />
                                                        </span>
                                                    </div>
                                                </div>

                                                {{-- Estimated Schedule --}}
                                                @if (count($scheduleRows) > 0)
                                                    <details class="group mt-4 border-t border-gray-100 pt-3 dark:border-gray-700">
                                                        <summary class="flex cursor-pointer items-center justify-between text-xs font-semibold uppercase tracking-wide text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">
                                                            <span>
                                                                {{ __('Estimated schedule') }}
                                                                @if ($activeCyclesCount < count($scheduleRows))
                                                                    ({{ $activeCyclesCount }} {{ __('cycles') }} <span class="text-xs font-normal text-emerald-600 dark:text-emerald-400 lowercase">({{ __('shortened from :total', ['total' => count($scheduleRows)]) }})</span>)
                                                                @else
                                                                    ({{ count($scheduleRows) }} {{ __('cycles') }})
                                                                @endif
                                                            </span>
                                                            <x-heroicon-o-chevron-down class="h-4 w-4 transition group-open:rotate-180" />
                                                        </summary>

                                                        @if (($schedule['current_cycle_contribution'] ?? '') === 'exempt_grace')
                                                            <p class="mt-2 text-xs text-violet-700 dark:text-violet-300">
                                                                {{ __('This cycle’s contribution is skipped because this cycle is grace.') }}
                                                            </p>
                                                        @endif

                                                        <div class="mt-3 max-h-64 overflow-y-auto rounded-lg ring-1 ring-gray-200 dark:ring-gray-700">
                                                            <table class="w-full divide-y divide-gray-100 text-xs dark:divide-gray-700">
                                                                <thead class="sticky top-0 z-10 bg-gray-50 text-[10px] font-semibold uppercase tracking-wide text-gray-500 shadow-sm dark:bg-gray-800 dark:text-gray-400">
                                                                    <tr>
                                                                        <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('EMI') }}</th>
                                                                        <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('Cycle') }}</th>
                                                                        <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('Due date') }}</th>
                                                                        <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Amount') }}</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-900/40">
                                                                    @foreach ($scheduleRows as $row)
                                                                        @php
                                                                            $kind = (string) ($row['kind'] ?? 'pending');
                                                                        @endphp
                                                                        <tr
                                                                            @class([
                                                                                'border-b border-gray-100 transition-colors last:border-b-0 dark:border-gray-700',
                                                                                'bg-violet-50/70 dark:bg-violet-950/20' => $kind === 'grace',
                                                                                'bg-emerald-50/70 dark:bg-emerald-950/25' => $kind === 'paid',
                                                                                'bg-amber-50/70 dark:bg-amber-950/25' => $kind === 'rolled_up',
                                                                                'bg-sky-50/70 dark:bg-sky-950/25' => $kind === 'skipped',
                                                                                'bg-gray-100/50 dark:bg-gray-800/40 opacity-60' => $kind === 'dropped',
                                                                            ])
                                                                        >
                                                                            <td class="whitespace-nowrap px-3 py-2 text-start font-medium tabular-nums text-gray-700 dark:text-gray-300">
                                                                                @if ($kind === 'grace')
                                                                                    <span class="inline-flex items-center rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold text-violet-800 dark:bg-violet-900/50 dark:text-violet-300">{{ __('Grace') }}</span>
                                                                                @elseif ($kind === 'paid')
                                                                                    <span class="inline-flex items-center rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">{{ __('Regular payment') }}</span>
                                                                                @elseif ($kind === 'rolled_up')
                                                                                    <span class="inline-flex items-center rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">{{ __('Rolled up') }}</span>
                                                                                @elseif ($kind === 'skipped')
                                                                                    <span class="inline-flex items-center rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-sky-800 dark:bg-sky-900/50 dark:text-sky-300">{{ __('Skipped') }}</span>
                                                                                @elseif ($kind === 'dropped')
                                                                                    <span class="inline-flex items-center rounded bg-gray-200 px-1.5 py-0.5 text-[10px] font-semibold text-gray-600 line-through dark:bg-gray-700 dark:text-gray-400">{{ __('Dropped') }}</span>
                                                                                @elseif ($kind === 'contribution_due')
                                                                                    <span class="inline-flex items-center rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ __('Contribution due') }}</span>
                                                                                @elseif ($kind === 'contribution_paid')
                                                                                    <span class="inline-flex items-center rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ __('Contribution paid') }}</span>
                                                                                @else
                                                                                    {{ $row['number'] ?? '—' }}
                                                                                @endif
                                                                            </td>
                                                                            <td @class([
                                                                                'whitespace-nowrap px-3 py-2 text-start text-gray-600 dark:text-gray-400',
                                                                                'line-through text-gray-400 dark:text-gray-500' => $kind === 'dropped',
                                                                            ])>
                                                                                {{ $row['cycle_label'] ?? '—' }}
                                                                            </td>
                                                                            <td @class([
                                                                                'whitespace-nowrap px-3 py-2 text-start tabular-nums text-gray-600 dark:text-gray-400',
                                                                                'line-through text-gray-400 dark:text-gray-500' => $kind === 'dropped',
                                                                            ])>
                                                                                {{ $row['due_date'] ?? '—' }}
                                                                            </td>
                                                                            <td @class([
                                                                                'whitespace-nowrap px-3 py-2 text-end font-semibold tabular-nums text-gray-900 dark:text-white',
                                                                                'line-through text-gray-400 dark:text-gray-500 font-normal' => $kind === 'dropped',
                                                                            ])>
                                                                                @if ($kind === 'dropped')
                                                                                    <span>—</span>
                                                                                @else
                                                                                    <x-member::amount :value="(float) ($row['amount'] ?? 0)" :currency="$currency" />
                                                                                @endif
                                                                            </td>
                                                                        </tr>
                                                                    @endforeach
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </details>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
        @endif

        {{-- TAB 3: LIFECYCLE SIMULATOR --}}
        @if ($this->activeTab === 'simulate')
            @if ($loanAmount > 0 && count($this->calculations) > 0 && empty($this->estimateBlockReason))
                @include('filament.member.pages.partials.loan-lifecycle-simulator', [
                    'currency' => $currency,
                    'calculations' => $this->calculations,
                ])
            @else
                <div class="rounded-xl border border-gray-200 bg-white p-8 text-center shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <x-heroicon-o-chart-bar-square class="mx-auto mb-3 h-12 w-12 text-gray-400 dark:text-gray-500" />
                    <h3 class="text-base font-bold text-gray-900 dark:text-white">
                        {{ __('Calculate a loan estimate first') }}
                    </h3>
                    <p class="mx-auto mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Enter a loan amount in the Loan Estimation tab and calculate an estimate to simulate the complete loan lifecycle.') }}
                    </p>
                    <div class="mt-4">
                        <x-filament::button
                            type="button"
                            color="primary"
                            size="md"
                            icon="heroicon-o-calculator"
                            wire:click="setActiveTab('estimate')"
                        >
                            {{ __('Go to loan estimation') }}
                        </x-filament::button>
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
