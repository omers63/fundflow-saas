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
        $moneyHtml = fn (float|int|string|null $value, int $precision = 2): string => \App\Filament\Support\MoneyDisplay::html($value, $currency, precision: $precision)?->toHtml() ?? e('—');
    @endphp

    <div class="ff-member-loan-calculator space-y-4 sm:space-y-5">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <a href="{{ $this->fundAccountUrl }}" class="ff-member-stat-card ff-member-loan-calc-status" data-accent="teal">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-circle-stack class="h-4 w-4 shrink-0 text-teal-600 dark:text-teal-400" />
                    <p class="ff-member-stat-card__label !mb-0">{{ __('Fund balance') }}</p>
                </div>
                <p class="mt-2 text-xl font-bold tabular-nums text-gray-900 dark:text-white sm:text-2xl">
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
        </div>

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

        <x-member::panel :title="__('Loan amount')" collapsible>
            <div class="space-y-4">
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

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300" for="loan-calculator-start-date">
                            {{ __('Projected start date') }}
                        </label>
                        <input
                            id="loan-calculator-start-date"
                            type="date"
                            wire:model.live="startDate"
                            class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Treat this as the disbursement date. The schedule uses the contribution cycle that contains this date (:cycle).', [
                                'cycle' => $this->currentCycleLabel,
                            ]) }}
                        </p>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300" for="loan-calculator-projected-contribution">
                            {{ __('Projected monthly contribution') }}
                        </label>
                        <select
                            id="loan-calculator-projected-contribution"
                            wire:model.live="projectedContributionAmount"
                            class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        >
                            @foreach ($this->projectedContributionOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Used for cycles after any active loan is settled with regular payments. Defaults to your current contribution amount.') }}
                        </p>
                    </div>
                </div>

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
                    $previewFundColumns = match (true) {
                        $showPreviewDisposition => 'sm:grid-cols-3',
                        $showPreviewExcess => 'sm:grid-cols-2',
                        default => 'sm:grid-cols-1',
                    };
                @endphp
                <div class="rounded-xl border border-gray-200/80 bg-white/80 p-3 ring-1 ring-gray-100 dark:border-gray-700 dark:bg-gray-900/20 dark:ring-gray-800">
                    <div class="grid grid-cols-1 gap-3 {{ $previewFundColumns }}">
                        <div class="ff-member-loan-calc-projected-fund rounded-lg bg-emerald-50/80 p-3 ring-1 ring-emerald-200/80 dark:bg-emerald-950/30 dark:ring-emerald-800/60">
                            @php
                                $projectedFundAtStart = (float) ($projection['projected_fund'] ?? 0);
                                $loanRepaymentCycles = (int) ($projection['loan_repayment_cycles'] ?? 0);
                                $contributionCycles = (int) ($projection['cycles_added'] ?? 0);
                                $loanInstallment = $projection['loan_repayment_installment'] ?? null;
                            @endphp
                            <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                                {{ __('Projected fund at start') }}
                            </p>
                            <p @class([
                                'mt-1 text-lg font-bold tabular-nums',
                                'text-emerald-900 dark:text-emerald-100' => $projectedFundAtStart >= 0,
                            ])>
                                <x-member::amount :value="$projectedFundAtStart" :currency="$currency" />
                            </p>
                            <p class="ff-member-loan-calc-projected-fund__formula mt-1 text-xs text-emerald-700/80 dark:text-emerald-300/80">
                                @if ($loanRepaymentCycles > 0 && $contributionCycles > 0 && $loanInstallment !== null)
                                    {!! __('Current fund :current + :loan_count × :installment + :contrib_count × :amount', [
                                        'current' => $moneyHtml($projection['current_fund'] ?? 0),
                                        'loan_count' => e((string) $loanRepaymentCycles),
                                        'installment' => $moneyHtml($loanInstallment),
                                        'contrib_count' => e((string) $contributionCycles),
                                        'amount' => $moneyHtml($projection['contribution_amount'] ?? 0),
                                    ]) !!}
                                @elseif ($loanRepaymentCycles > 0 && $contributionCycles > 0)
                                    {!! __('Current fund :current + :settlement (loan repayments) + :count × :amount', [
                                        'current' => $moneyHtml($projection['current_fund'] ?? 0),
                                        'settlement' => $moneyHtml($projection['loan_repayment_amount'] ?? 0),
                                        'count' => e((string) $contributionCycles),
                                        'amount' => $moneyHtml($projection['contribution_amount'] ?? 0),
                                    ]) !!}
                                @elseif ($loanRepaymentCycles > 0 && $loanInstallment !== null)
                                    {!! __('Current fund :current + :loan_count × :installment', [
                                        'current' => $moneyHtml($projection['current_fund'] ?? 0),
                                        'loan_count' => e((string) $loanRepaymentCycles),
                                        'installment' => $moneyHtml($loanInstallment),
                                    ]) !!}
                                @elseif ($loanRepaymentCycles > 0)
                                    {!! __('Current fund :current + :settlement (loan repayments)', [
                                        'current' => $moneyHtml($projection['current_fund'] ?? 0),
                                        'settlement' => $moneyHtml($projection['loan_repayment_amount'] ?? 0),
                                    ]) !!}
                                @elseif ($contributionCycles > 0)
                                    {!! __('Current fund :current + :count × :amount', [
                                        'current' => $moneyHtml($projection['current_fund'] ?? 0),
                                        'count' => e((string) $contributionCycles),
                                        'amount' => $moneyHtml($projection['contribution_amount'] ?? 0),
                                    ]) !!}
                                @else
                                    {{ __('Using your current fund balance.') }}
                                @endif
                            </p>
                        </div>

                        @if ($showPreviewExcess)
                            <div class="rounded-lg bg-amber-50/80 p-3 ring-1 ring-amber-200/80 dark:bg-amber-950/30 dark:ring-amber-800/60">
                                <p class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">
                                    {{ __('Excess fund above share') }}
                                </p>
                                <p class="mt-1 text-lg font-bold tabular-nums text-amber-900 dark:text-amber-100">
                                    <x-member::amount :value="$previewExcess" :currency="$currency" />
                                </p>
                            </div>
                        @endif

                        @if ($showPreviewDisposition)
                            <div class="rounded-lg bg-sky-50/80 p-3 ring-1 ring-sky-200/80 dark:bg-sky-950/30 dark:ring-sky-800/60">
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
                </div>

                @if ($this->activeTiers->isNotEmpty())
                    <details class="ff-member-loan-calc-tiers-wrap group rounded-xl ring-1 ring-gray-200 dark:ring-gray-700" open>
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 marker:content-none [&::-webkit-details-marker]:hidden">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ __('Loan tiers') }}
                            </span>
                            <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-180 dark:text-gray-500" />
                        </summary>
                        <div class="border-t border-gray-100 px-2.5 pb-2.5 pt-2 dark:border-gray-700">
                            <div class="ff-member-loan-calc-tiers">
                                @foreach ($this->activeTiers as $tier)
                                    @php
                                        $tierMin = round((float) $tier->min_amount, 2);
                                        $tierMax = round((float) $tier->max_amount, 2);
                                        $tierBoundsEqual = abs($tierMin - $tierMax) < 0.01;
                                        $minSelected = abs($loanAmount - $tierMin) < 0.01;
                                        $maxSelected = ! $tierBoundsEqual && abs($loanAmount - $tierMax) < 0.01;
                                        $tierInRange = $loanAmount > 0.00001
                                            && $loanAmount + 0.00001 >= $tierMin
                                            && $loanAmount - 0.00001 <= $tierMax;
                                    @endphp
                                    <div
                                        @class([
                                            'ff-member-loan-calc-tier flex h-full min-w-0 flex-col gap-1.5 rounded-xl px-2 py-2 ring-1',
                                            'bg-primary-50/70 ring-primary-300 dark:bg-primary-950/30 dark:ring-primary-700' => $tierInRange,
                                            'bg-gray-50 ring-gray-200 dark:bg-gray-800/60 dark:ring-gray-700' => ! $tierInRange,
                                        ])
                                    >
                                        <span class="truncate text-center text-xs font-semibold text-gray-700 dark:text-gray-200">
                                            {{ $tier->label }}
                                        </span>
                                        <div
                                            class="flex min-h-8 w-full min-w-0 flex-1 overflow-hidden rounded-lg ring-1 ring-gray-200 dark:ring-gray-600"
                                            role="group"
                                            aria-label="{{ __('Loan amounts for :tier', ['tier' => $tier->label]) }}"
                                        >
                                            <button
                                                type="button"
                                                wire:click="$set('loanAmount', {{ $tierMin }})"
                                                @class([
                                                    'min-w-0 flex-1 px-1 py-1.5 text-center text-[11px] font-semibold leading-tight tabular-nums transition-colors sm:text-xs',
                                                    'bg-primary-600 text-white' => $minSelected,
                                                    'bg-white text-gray-700 hover:bg-primary-50 hover:text-primary-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-primary-950 dark:hover:text-primary-200' => ! $minSelected,
                                                ])
                                            >
                                                <span class="block truncate">{!! \App\Filament\Support\MoneyDisplay::html($tierMin, $currency, precision: 0)?->toHtml() !!}</span>
                                            </button>
                                            @unless ($tierBoundsEqual)
                                                <button
                                                    type="button"
                                                    wire:click="$set('loanAmount', {{ $tierMax }})"
                                                    @class([
                                                        'min-w-0 flex-1 border-s border-gray-200 px-1 py-1.5 text-center text-[11px] font-semibold leading-tight tabular-nums transition-colors dark:border-gray-600 sm:text-xs',
                                                        'bg-primary-600 text-white' => $maxSelected,
                                                        'bg-white text-gray-700 hover:bg-primary-50 hover:text-primary-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-primary-950 dark:hover:text-primary-200' => ! $maxSelected,
                                                    ])
                                                >
                                                    <span class="block truncate">{!! \App\Filament\Support\MoneyDisplay::html($tierMax, $currency, precision: 0)?->toHtml() !!}</span>
                                                </button>
                                            @endunless
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </details>
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

        @if ($loanAmount > 0 && count($this->calculations) > 0)
            <div class="mt-4 grid grid-cols-2 gap-2 sm:mt-5 sm:flex sm:flex-wrap" role="tablist" aria-label="{{ __('Calculator mode') }}">
                <button
                    type="button"
                    wire:click="setCalculatorMode('estimate')"
                    @class([
                        'inline-flex w-full items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold ring-1 transition-colors sm:w-auto',
                        'bg-primary-600 text-white ring-primary-600' => $this->calculatorMode === 'estimate',
                        'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700' => $this->calculatorMode !== 'estimate',
                    ])
                >
                    {{ __('Estimate') }}
                </button>
                <button
                    type="button"
                    wire:click="setCalculatorMode('simulate')"
                    @class([
                        'inline-flex w-full items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold ring-1 transition-colors sm:w-auto',
                        'bg-primary-600 text-white ring-primary-600' => $this->calculatorMode === 'simulate',
                        'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700' => $this->calculatorMode !== 'simulate',
                    ])
                >
                    {{ __('Lifecycle simulator') }}
                </button>
            </div>
        @endif

        @if ($loanAmount > 0)
            @if (filled($this->estimateBlockReason))
                <div class="rounded-xl bg-white p-6 text-center shadow-sm ring-1 ring-amber-200 dark:bg-gray-800 dark:ring-amber-800/50 sm:p-8">
                    <x-heroicon-o-exclamation-triangle class="mx-auto mb-3 h-10 w-10 text-amber-400" />
                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('Cannot estimate or simulate this loan') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ $this->estimateBlockReason }}
                    </p>
                </div>
            @elseif (count($this->calculations) > 0 && $this->calculatorMode === 'simulate')
                @include('filament.member.pages.partials.loan-lifecycle-simulator', [
                    'currency' => $currency,
                    'calculations' => $this->calculations,
                ])
            @elseif (count($this->calculations) > 0)
                @foreach ($this->calculations as $calc)
                    @php
                        $memberPct = $loanAmount > 0 ? min(100, $calc['member_portion'] / $loanAmount * 100) : 0;
                        $masterPct = 100 - $memberPct;
                        $eligibilityNeed = (float) $calc['eligibility_amt'];
                        $eligibilityProgress = $eligibilityNeed > 0.00001
                            ? (int) min(100, round($projectedFund / $eligibilityNeed * 100))
                            : 100;
                    @endphp

                    <div class="ff-member-loan-calc-result overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 bg-gradient-to-r from-primary-50 to-indigo-50 px-4 py-3 dark:border-gray-700 dark:from-primary-950/40 dark:to-indigo-950/30 sm:px-5 sm:py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $calc['tier']->label }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $this->formatTierRange($calc['tier']) }}</p>
                            </div>
                            <div class="text-end tabular-nums">
                                <span class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $calc['duration_months'] }}</span>
                                <span class="ms-1 text-sm text-gray-500 dark:text-gray-400">{{ __('months') }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 border-b border-gray-100 px-4 py-4 sm:grid-cols-3 sm:px-5 dark:border-gray-700">
                            <div class="ff-member-loan-calc-highlight">
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Total to repay') }}</p>
                                <p class="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                                    <x-member::amount :value="$calc['total_repay']" :currency="$currency" />
                                </p>
                                <p class="text-xs text-gray-400">{{ __('master portion + settlement') }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Monthly installment') }}</p>
                                <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">
                                    <x-member::amount :value="$calc['min_installment']" :currency="$currency" />
                                </p>
                                <p class="text-xs text-gray-400">{{ __('minimum') }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Duration') }}</p>
                                <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">
                                    {{ __('~:years years', ['years' => number_format($calc['duration_months'] / 12, 1)]) }}
                                </p>
                                <p class="text-xs text-gray-400">
                                    {{ __(':count monthly payments', ['count' => $calc['duration_months']]) }}
                                </p>
                            </div>
                        </div>

                        <div class="px-4 py-4 sm:px-5">
                            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('This loan') }}</p>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <div class="min-w-0">
                                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Your fund portion') }}</p>
                                    <p class="mt-1 font-semibold text-emerald-600 dark:text-emerald-400">
                                        <x-member::amount :value="$calc['member_portion']" :currency="$currency" />
                                    </p>
                                    <p class="text-xs text-gray-400">{{ __('from your fund account') }}</p>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Fund contribution') }}</p>
                                    <p class="mt-1 font-semibold text-amber-600 dark:text-amber-400">
                                        <x-member::amount :value="$calc['master_portion']" :currency="$currency" />
                                    </p>
                                    <p class="text-xs text-gray-400">{{ __('from master fund') }}</p>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Settlement amount') }}</p>
                                    <p class="mt-1 font-semibold text-sky-700 dark:text-sky-300">
                                        <x-member::amount :value="$calc['settlement_amt']" :currency="$currency" />
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        {{ __(':percent% of loan amount', ['percent' => $settlementPercent]) }}
                                    </p>
                                </div>
                                <div class="min-w-0">
                                    <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Loan funding split') }}</p>
                                    <div class="flex h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                        @if ($memberPct > 0)
                                            <div class="h-full bg-emerald-500" style="width: {{ $memberPct }}%"></div>
                                        @endif
                                        @if ($masterPct > 0)
                                            <div class="h-full bg-amber-400" style="width: {{ $masterPct }}%"></div>
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
                        </div>

                        <div class="border-t border-violet-100 bg-violet-50/70 px-4 py-4 dark:border-violet-500/20 dark:bg-violet-950/25 sm:px-5">
                            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-violet-800 dark:text-violet-200">{{ __('After this loan') }}</p>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="min-w-0">
                                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Eligibility threshold amount') }}</p>
                                    <p class="mt-1 text-lg font-bold text-violet-800 dark:text-violet-200">
                                        <x-member::amount :value="$calc['eligibility_amt']" :currency="$currency" />
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {!! __(':percent% of tier ceiling (:ceiling)', [
                                            'percent' => $eligibilityPercent,
                                            'ceiling' => $moneyHtml($calc['eligibility_base'], 0),
                                        ]) !!}
                                    </p>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('After repayment you will need this fund balance before you can apply again.') }}
                                    </p>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ ((int) ($projection['loan_repayment_cycles'] ?? 0) + (int) ($projection['cycles_added'] ?? 0)) > 0 ? __('Projected fund at start') : __('Your fund now') }}
                                    </p>
                                    <p class="mt-1 font-semibold text-gray-900 dark:text-white">
                                        <x-member::amount :value="$projectedFund" :currency="$currency" />
                                    </p>
                                    <div class="mt-2">
                                        <x-member::progress-bar
                                            :percent="$eligibilityProgress"
                                            :tone="$eligibilityProgress >= 100 ? 'success' : 'warning'"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        @php
                            $schedule = $calc['schedule'] ?? ['rows' => []];
                            $rowKind = fn (array $row): string => (string) ($row['kind'] ?? '');
                        @endphp
                        @if (count($schedule['rows'] ?? []) > 0)
                            <details class="group border-t border-gray-100 dark:border-gray-700" open>
                                <summary class="flex cursor-pointer list-none items-end justify-between gap-3 px-4 py-4 marker:content-none sm:px-5 [&::-webkit-details-marker]:hidden">
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            {{ __('Estimated schedule') }}
                                        </p>
                                        @if (filled($schedule['first_due_label'] ?? null))
                                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                                {{ __('First EMI :first · Last EMI :last', [
                                                    'first' => $schedule['first_due_label'],
                                                    'last' => $schedule['last_due_label'] ?? '—',
                                                ]) }}
                                            </p>
                                        @elseif ((int) ($schedule['payable_count'] ?? 0) === 0)
                                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                                {{ __('No remaining EMIs — early settlement covers the schedule.') }}
                                            </p>
                                        @endif
                                        @if (filled($schedule['current_cycle_contribution_label'] ?? null))
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                {{ $schedule['current_cycle_contribution_label'] }}
                                            </p>
                                        @endif
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <x-member::chip variant="purple">
                                            {{ __('Grace') }}:
                                            {{ $this->graceCycleOptions[(int) ($schedule['grace_cycles'] ?? 0)] ?? __('None') }}
                                        </x-member::chip>
                                        <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-180 dark:text-gray-500" />
                                    </div>
                                </summary>
                                <div class="px-4 pb-4 sm:px-5">
                                    <div class="ff-member-loan-calc-schedule max-h-80 overflow-y-auto rounded-lg ring-1 ring-gray-200 dark:ring-gray-700">
                                        <div class="ff-member-loan-calc-schedule-header border-b border-gray-100 bg-gray-50 py-2 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-400">
                                            <span>{{ __('EMI') }}</span>
                                            <span>{{ __('Cycle') }}</span>
                                            <span>{{ __('Due date') }}</span>
                                            <span>{{ __('Amount') }}</span>
                                        </div>
                                        @foreach ($schedule['rows'] as $row)
                                            @php $kind = $rowKind($row); @endphp
                                            <div
                                                @class([
                                                    'ff-member-loan-calc-schedule-row border-b border-gray-100 px-3 py-2 last:border-b-0 dark:border-gray-700 sm:px-0',
                                                    'ff-member-loan-calc-schedule-row--grace bg-violet-50/70 dark:bg-violet-950/20' => $kind === 'grace',
                                                    'ff-member-loan-calc-schedule-row--contribution bg-amber-50/70 dark:bg-amber-950/20' => $kind === 'contribution_due',
                                                    'ff-member-loan-calc-schedule-row--paid bg-emerald-50/50 dark:bg-emerald-950/20' => in_array($kind, ['contribution_paid', 'rolled_up', 'paid'], true),
                                                    'ff-member-loan-calc-schedule-row--skipped bg-slate-50 dark:bg-slate-900/40' => in_array($kind, ['skipped', 'dropped'], true),
                                                ])
                                            >
                                                <div class="text-xs font-semibold tabular-nums text-gray-700 dark:text-gray-200">
                                                    {{ $row['number'] ?? '—' }}
                                                </div>
                                                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                                                    @if ($kind === 'grace')
                                                        <x-member::chip variant="purple">{{ __('Grace') }}</x-member::chip>
                                                    @elseif ($kind === 'contribution_due')
                                                        <x-member::chip variant="amber">{{ __('Contribution due') }}</x-member::chip>
                                                    @elseif ($kind === 'contribution_paid')
                                                        <x-member::chip variant="green">{{ __('Paid') }}</x-member::chip>
                                                    @elseif ($kind === 'paid')
                                                        <x-member::chip variant="green">{{ __('Regular payment') }}</x-member::chip>
                                                    @elseif ($kind === 'rolled_up')
                                                        <x-member::chip variant="blue">{{ __('Rolled up') }}</x-member::chip>
                                                    @elseif ($kind === 'skipped')
                                                        <x-member::chip variant="gray">{{ __('Skipped') }}</x-member::chip>
                                                    @elseif ($kind === 'dropped')
                                                        <x-member::chip variant="gray">{{ __('Removed') }}</x-member::chip>
                                                    @endif
                                                    <p class="text-sm text-gray-800 dark:text-gray-100">{{ $row['cycle_label'] }}</p>
                                                </div>
                                                <p class="min-w-0 text-sm text-gray-500 dark:text-gray-400">
                                                    @if ($kind === 'grace')
                                                        —
                                                    @else
                                                        {{ $row['due_label'] ?? '—' }}
                                                    @endif
                                                </p>
                                                <p @class([
                                                    'text-sm font-semibold tabular-nums sm:text-end',
                                                    'text-gray-400 dark:text-gray-500' => in_array($kind, ['skipped', 'dropped'], true),
                                                    'text-gray-900 dark:text-white' => ! in_array($kind, ['skipped', 'dropped'], true),
                                                ])>
                                                    @if ($kind === 'grace' || $kind === 'skipped')
                                                        {{ __('No EMI') }}
                                                    @elseif ($kind === 'contribution_due')
                                                        {{ __('Contribution due') }}
                                                    @elseif ($kind === 'contribution_paid')
                                                        {{ __('Contribution paid') }}
                                                    @elseif ($kind === 'dropped')
                                                        {{ __('Removed by roll-up') }}
                                                    @else
                                                        <x-member::amount :value="$row['amount']" :currency="$currency" />
                                                    @endif
                                                </p>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </details>
                        @endif
                    </div>
                @endforeach

                <p class="text-center text-xs text-gray-400 dark:text-gray-500">
                    {{ __('* These are estimates based on current tier settings and your fund balance. Actual terms may vary upon approval.') }}
                </p>
            @else
                <div class="rounded-xl bg-white p-6 text-center shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 sm:p-8">
                    <x-heroicon-o-exclamation-triangle class="mx-auto mb-3 h-10 w-10 text-amber-400" />
                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('No matching loan tier') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {!! __(':amount does not fall within any active loan tier range.', [
                            'amount' => $moneyHtml($loanAmount),
                        ]) !!}
                    </p>
                    @if ($this->activeTiers->isNotEmpty())
                        <div class="mt-4 flex flex-wrap justify-center gap-2">
                            @foreach ($this->activeTiers as $tier)
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                                    {{ $tier->label }}: {{ $this->formatTierRange($tier) }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        @else
            <div class="rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 sm:p-10">
                <x-heroicon-o-calculator class="mx-auto mb-3 h-12 w-12 text-gray-300 dark:text-gray-600" />
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Enter a loan amount above to see your repayment estimate.') }}
                </p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
