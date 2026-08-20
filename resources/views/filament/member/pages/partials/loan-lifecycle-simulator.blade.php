@php
    $sim = $this->simulation;
    $simActive = is_array($sim) && ($sim['status'] ?? '') === \App\Services\MemberLoanLifecycleSimulator::STATUS_ACTIVE;
    $simClosed = is_array($sim) && in_array($sim['status'] ?? '', [
        \App\Services\MemberLoanLifecycleSimulator::STATUS_PAID,
        \App\Services\MemberLoanLifecycleSimulator::STATUS_FULLY_SETTLED,
    ], true);
    $scheduleRows = is_array($sim['schedule_rows'] ?? null) ? $sim['schedule_rows'] : [];
@endphp

<div class="ff-member-loan-sim space-y-6">
    <x-member::panel :title="__('Lifecycle simulator')">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('Educational what-if based on the loan lifecycle. It does not post to your live loan or accounts. Live repayment rules will be aligned later.') }}
        </p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            {{ __('While the loan is active you can combine regular payments, partial early settlement, and full early settlement. Full early settlement is available at any time — including after earlier payments.') }}
        </p>

        @if (count($calculations) > 1)
            <div class="mt-3">
                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300" for="loan-sim-tier">
                    {{ __('Tier for simulation') }}
                </label>
                <select
                    id="loan-sim-tier"
                    wire:model.live="simulateTierIndex"
                    class="block w-full max-w-md rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                >
                    @foreach ($calculations as $index => $calc)
                        <option value="{{ $index }}">{{ $calc['tier']->label }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </x-member::panel>

    @if (! is_array($sim))
        <div class="rounded-xl bg-white p-6 text-center shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Start the simulation from your current estimate.') }}
            </p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 bg-gradient-to-r from-slate-50 to-indigo-50 px-4 py-3 dark:border-gray-700 dark:from-slate-950/40 dark:to-indigo-950/30 sm:px-5 sm:py-4">
                <div class="min-w-0">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Simulated status') }}</p>
                    <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $sim['status_label'] ?? __('Active') }}</p>
                </div>
                <x-member::chip :variant="$simClosed ? 'green' : 'blue'">
                    {{ $sim['status_label'] ?? __('Active') }}
                </x-member::chip>
            </div>

            <div class="grid grid-cols-1 gap-3 border-b border-gray-100 px-4 py-4 sm:grid-cols-2 lg:grid-cols-4 sm:px-5 dark:border-gray-700">
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Maturity amount') }}</p>
                    <p class="mt-1 font-semibold text-gray-900 dark:text-white">
                        <x-member::amount :value="$sim['maturity_amount']" :currency="$currency" />
                    </p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Total repaid') }}</p>
                    <p class="mt-1 font-semibold text-gray-900 dark:text-white">
                        <x-member::amount :value="$sim['total_repaid']" :currency="$currency" />
                    </p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Remaining') }}</p>
                    <p class="mt-1 font-semibold text-gray-900 dark:text-white">
                        <x-member::amount :value="$sim['remaining_maturity']" :currency="$currency" />
                    </p>
                    @if ($simActive)
                        <p class="text-xs text-gray-400">
                            {{ __(':count month(s) left', ['count' => (int) ($sim['remaining_months'] ?? 0)]) }}
                        </p>
                    @endif
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Simulated fund') }}</p>
                    <p class="mt-1 font-semibold">
                        <x-member::amount :value="$sim['fund_balance']" :currency="$currency" />
                    </p>
                    <p class="text-xs text-gray-400">
                        {{ __('Pre-loan :amount', [
                            'amount' => \App\Filament\Support\MoneyDisplay::format((float) $sim['pre_loan_fund'], $currency) ?? '—',
                        ]) }}
                    </p>
                    @if (((float) ($sim['cash_balance'] ?? 0)) > 0.00001 || ($sim['cash_out_excess_fund'] ?? false))
                        <p class="mt-2 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Simulated cash') }}</p>
                        <p class="mt-0.5 font-semibold text-sky-700 dark:text-sky-300">
                            <x-member::amount :value="$sim['cash_balance'] ?? 0" :currency="$currency" />
                        </p>
                        @if (((float) ($sim['excess_to_cash'] ?? 0)) > 0.00001)
                            <p class="text-xs text-gray-400">
                                {{ __('Includes excess transferred at disbursement (:amount)', [
                                    'amount' => \App\Filament\Support\MoneyDisplay::format((float) $sim['excess_to_cash'], $currency) ?? '—',
                                ]) }}
                            </p>
                        @endif
                    @endif
                </div>
            </div>

            @if ($simActive)
                <div class="grid grid-cols-1 gap-3 border-b border-gray-100 px-4 py-4 sm:grid-cols-2 sm:px-5 dark:border-gray-700">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Expected maturity') }}</p>
                        <p class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-100">
                            {{ $sim['expected_maturity_date'] ?? '—' }}
                        </p>
                        <p class="text-xs text-gray-400">
                            {{ __('Next due :date', ['date' => $sim['next_due_date'] ?? '—']) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Full settlement amount') }}</p>
                        <p class="mt-1 font-semibold text-violet-700 dark:text-violet-300">
                            <x-member::amount :value="$sim['full_settlement_amount']" :currency="$currency" />
                        </p>
                        <p class="text-xs text-gray-400">
                            {{ __('Pre-loan fund − current simulated fund') }}
                        </p>
                    </div>
                </div>

                <div class="border-b border-gray-100 px-4 py-4 sm:px-5 dark:border-gray-700">
                    <div class="rounded-lg bg-slate-50/80 p-3 ring-1 ring-slate-200 dark:bg-slate-950/30 dark:ring-slate-700/60">
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Apply payments') }}</p>
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                            {{ __('Regular payment uses one installment. Enter an amount only for partial early settlement. Full early settlement closes the loan and restores the pre-loan fund balance.') }}
                        </p>

                        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <div class="flex flex-col gap-3">
                                <div>
                                    <p class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Regular payment') }}</p>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('Always pays the installment amount (:amount).', [
                                            'amount' => \App\Filament\Support\MoneyDisplay::format((float) ($sim['min_installment'] ?? 0), $currency) ?? '—',
                                        ]) }}
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <x-filament::button type="button" color="success" size="sm" wire:click="applySimulationRegularPayment">
                                        {{ __('Apply regular payment') }}
                                    </x-filament::button>
                                </div>
                            </div>

                            <div class="flex flex-col gap-3">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="loan-sim-payment">
                                        {{ __('Partial settlement amount') }}
                                    </label>
                                    <input
                                        id="loan-sim-payment"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        wire:model="simulationPaymentAmount"
                                        class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('Used for partial early settlement only.') }}
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <x-filament::button type="button" color="primary" size="sm" wire:click="applySimulationPartialEarlySettlement">
                                        {{ __('Apply partial early settlement') }}
                                    </x-filament::button>
                                </div>
                            </div>

                            <div class="flex flex-col gap-3">
                                <div>
                                    <p class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Full settlement amount') }}</p>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('Pay :amount to close now.', [
                                            'amount' => \App\Filament\Support\MoneyDisplay::format((float) ($sim['full_settlement_amount'] ?? 0), $currency) ?? '—',
                                        ]) }}
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <x-filament::button type="button" color="warning" size="sm" wire:click="applySimulationFullEarlySettlement">
                                        {{ __('Apply full early settlement') }}
                                    </x-filament::button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @if ($simClosed)
                <div class="space-y-3 border-b border-gray-100 px-4 py-4 sm:px-5 dark:border-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('After close') }}</p>
                        <x-member::chip :variant="($sim['eligible_for_new_loan'] ?? false) ? 'green' : 'amber'">
                            {{ ($sim['eligible_for_new_loan'] ?? false) ? __('Eligible for new loan') : __('Not yet eligible') }}
                        </x-member::chip>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ __('Need fund ≥ :amount (:percent% of tier ceiling). Resume any allowable contribution amount.', [
                            'amount' => \App\Filament\Support\MoneyDisplay::format((float) $sim['eligibility_amt'], $currency) ?? '—',
                            'percent' => $this->eligibilityPct > 0 ? round($this->eligibilityPct * 100) : '—',
                        ]) }}
                    </p>
                    @unless ($sim['eligible_for_new_loan'] ?? false)
                        <div class="flex flex-wrap items-end gap-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="loan-sim-contribution">
                                    {{ __('Contribution amount') }}
                                </label>
                                <select
                                    id="loan-sim-contribution"
                                    wire:model="simulationContributionAmount"
                                    class="block w-full min-w-[10rem] rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                >
                                    @foreach ($this->projectedContributionOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <x-filament::button type="button" color="primary" size="sm" wire:click="applySimulationContribution">
                                {{ __('Apply contribution cycle') }}
                            </x-filament::button>
                        </div>
                    @endunless
                </div>
            @endif

            <div class="border-b border-gray-100 px-4 py-4 sm:px-5 dark:border-gray-700">
                <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('Updating schedule') }}
                        </p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            {{ __('Cycles and due dates refresh as you apply payments or settlement.') }}
                        </p>
                    </div>
                    <div class="flex w-full flex-wrap items-end justify-between gap-3 sm:w-auto sm:justify-end sm:gap-6">
                        <div class="flex flex-wrap items-end gap-4 text-end tabular-nums sm:gap-5">
                            <div>
                                <span class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ (int) ($sim['schedule_count'] ?? 0) }}</span>
                                <span class="ms-1 text-sm text-gray-500 dark:text-gray-400">{{ __('total cycle(s)') }}</span>
                            </div>
                            <div>
                                <span class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ (int) ($sim['pending_count'] ?? 0) }}</span>
                                <span class="ms-1 text-sm text-gray-500 dark:text-gray-400">{{ __('pending installment(s)') }}</span>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('Projected loan maturity date') }}
                                </p>
                                <p class="mt-0.5 text-sm font-semibold tabular-nums text-gray-800 dark:text-gray-100">
                                    @if (filled($sim['expected_maturity_date'] ?? null))
                                        {{ \Carbon\Carbon::parse($sim['expected_maturity_date'])->locale(app()->getLocale())->translatedFormat('j M Y') }}
                                    @else
                                        —
                                    @endif
                                </p>
                            </div>
                        </div>
                        <div class="ms-auto sm:ms-0">
                            <x-filament::button type="button" color="danger" size="sm" icon="heroicon-o-arrow-path" wire:click="startSimulationFromEstimate">
                                {{ __('Reset simulation') }}
                            </x-filament::button>
                        </div>
                    </div>
                </div>

                <div class="ff-member-loan-calc-schedule max-h-80 overflow-y-auto rounded-lg ring-1 ring-gray-200 dark:ring-gray-700">
                    <div class="ff-member-loan-calc-schedule-header border-b border-gray-100 bg-gray-50 py-2 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-400">
                        <span>{{ __('EMI') }}</span>
                        <span>{{ __('Cycle') }}</span>
                        <span>{{ __('Due date') }}</span>
                        <span>{{ __('Amount') }}</span>
                    </div>
                    @forelse ($scheduleRows as $row)
                        @php
                            $kind = (string) ($row['kind'] ?? 'pending');
                            $days = $row['days_until_due'] ?? null;
                        @endphp
                        <div
                            @class([
                                'ff-member-loan-calc-schedule-row border-b border-gray-100 px-3 py-2 last:border-b-0 dark:border-gray-700 sm:px-0',
                                'ff-member-loan-calc-schedule-row--grace bg-violet-50/70 dark:bg-violet-950/20' => $kind === 'grace',
                                'ff-member-loan-calc-schedule-row--contribution bg-amber-50/70 dark:bg-amber-950/20' => $kind === 'contribution_due',
                                'ff-member-loan-calc-schedule-row--contribution-paid bg-emerald-50/50 dark:bg-emerald-950/20' => $kind === 'contribution_paid',
                                'ff-member-loan-calc-schedule-row--regular bg-emerald-50/70 dark:bg-emerald-950/25' => $kind === 'paid',
                                'ff-member-loan-calc-schedule-row--partial bg-primary-50/70 dark:bg-primary-950/25' => in_array($kind, ['rolled_up', 'skipped', 'dropped'], true),
                                'ff-member-loan-calc-schedule-row--full bg-amber-50/70 dark:bg-amber-950/25' => $kind === 'cancelled',
                            ])
                        >
                            <div class="text-xs font-semibold tabular-nums text-gray-700 dark:text-gray-200">
                                @if (in_array($kind, ['grace', 'contribution_due', 'contribution_paid'], true))
                                    —
                                @else
                                    {{ $row['number'] ?? '—' }}
                                @endif
                            </div>
                            <div class="min-w-0">
                                @if ($kind === 'grace')
                                    <x-member::chip variant="purple">{{ __('Grace') }}</x-member::chip>
                                @elseif ($kind === 'contribution_due')
                                    <x-member::chip variant="amber">{{ __('Contribution due') }}</x-member::chip>
                                @elseif ($kind === 'contribution_paid')
                                    <x-member::chip variant="green">{{ __('Paid') }}</x-member::chip>
                                @elseif ($kind === 'paid')
                                    <x-member::chip variant="green">{{ __('Regular payment') }}</x-member::chip>
                                @elseif ($kind === 'rolled_up')
                                    <x-member::chip variant="blue">{{ __('Partial settlement') }}</x-member::chip>
                                @elseif ($kind === 'skipped')
                                    <x-member::chip variant="blue">{{ __('Skipped') }}</x-member::chip>
                                @elseif ($kind === 'cancelled')
                                    <x-member::chip variant="amber">{{ __('Full settlement') }}</x-member::chip>
                                @elseif ($kind === 'dropped')
                                    <x-member::chip variant="blue">{{ __('Removed') }}</x-member::chip>
                                @elseif ($kind === 'pending')
                                    <x-member::chip variant="gray">{{ __('Pending') }}</x-member::chip>
                                @endif
                                <p class="text-sm text-gray-800 dark:text-gray-100">{{ $row['cycle_label'] ?? '—' }}</p>
                            </div>
                            <div class="min-w-0 text-sm text-gray-500 dark:text-gray-400">
                                <p>{{ $row['due_label'] ?? ($row['due_date'] ?? '—') }}</p>
                                @if ($kind === 'pending' && is_numeric($days))
                                    <p class="text-xs">
                                        @if ((int) $days > 0)
                                            {{ __('In :days days', ['days' => (int) $days]) }}
                                        @elseif ((int) $days === 0)
                                            {{ __('Due today') }}
                                        @else
                                            {{ __(':days days overdue', ['days' => abs((int) $days)]) }}
                                        @endif
                                    </p>
                                @elseif (filled($row['note'] ?? null))
                                    <p class="text-xs">{{ $row['note'] }}</p>
                                @endif
                            </div>
                            <p @class([
                                'text-sm font-semibold tabular-nums sm:text-end',
                                'text-gray-400 dark:text-gray-500' => in_array($kind, ['skipped', 'grace', 'contribution_paid'], true)
                                    || ($kind === 'cancelled' && ((float) ($row['amount'] ?? 0)) <= 0.00001),
                                'text-gray-900 dark:text-white' => ! in_array($kind, ['skipped', 'grace', 'contribution_paid'], true)
                                    && ! ($kind === 'cancelled' && ((float) ($row['amount'] ?? 0)) <= 0.00001),
                            ])>
                                @if (in_array($kind, ['grace', 'contribution_paid'], true))
                                    —
                                @elseif ($kind === 'skipped' || ($kind === 'cancelled' && ((float) ($row['amount'] ?? 0)) <= 0.00001))
                                    {{ __('No EMI') }}
                                @else
                                    <x-member::amount :value="$row['amount'] ?? 0" :currency="$currency" />
                                @endif
                            </p>
                        </div>
                    @empty
                        <div class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('No schedule rows.') }}
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="px-4 py-4 sm:px-5">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Simulation history') }}</p>
                <div class="max-h-72 overflow-auto rounded-lg ring-1 ring-gray-200 dark:ring-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="sticky top-0 bg-gray-50 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-800/80 dark:text-gray-400">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-center">{{ __('Date') }}</th>
                                <th scope="col" class="px-3 py-2 text-center">{{ __('Event') }}</th>
                                <th scope="col" class="px-3 py-2 text-center">{{ __('Amount') }}</th>
                                <th scope="col" class="px-3 py-2 text-center">{{ __('Fund') }}</th>
                                <th scope="col" class="px-3 py-2 text-center">{{ __('Outstanding fund portion') }}</th>
                                <th scope="col" class="px-3 py-2 text-center">{{ __('Remaining maturity') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse (array_reverse($sim['history'] ?? []) as $event)
                                @php
                                    $fundBefore = (float) ($event['fund_before'] ?? $event['fund_balance'] ?? 0);
                                    $fundAfter = (float) ($event['fund_balance'] ?? 0);
                                    $fundDelta = (float) ($event['fund_delta'] ?? ($fundAfter - $fundBefore));
                                    $outstandingAfter = (float) ($event['outstanding_fund_portion'] ?? 0);
                                    $outstandingBefore = (float) ($event['outstanding_before'] ?? $outstandingAfter);
                                    $remainingAfter = (float) ($event['remaining_maturity'] ?? 0);
                                    $remainingBefore = (float) ($event['remaining_before'] ?? $remainingAfter);
                                    $isContribution = ($event['type'] ?? '') === 'contribution';
                                @endphp
                                <tr class="bg-white dark:bg-gray-800/40">
                                    <td class="px-3 py-2 align-top text-center tabular-nums text-gray-600 dark:text-gray-300">
                                        {{ $event['at_label'] ?? ($event['at'] ?? '—') }}
                                    </td>
                                    <td class="px-3 py-2 align-top text-center font-medium text-gray-800 dark:text-gray-100">
                                        {{ $event['label'] ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 align-top text-center tabular-nums text-gray-700 dark:text-gray-200">
                                        @if (((float) ($event['amount'] ?? 0)) > 0.00001)
                                            <x-member::amount :value="$event['amount']" :currency="$currency" />
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 align-top text-center tabular-nums text-gray-600 dark:text-gray-300">
                                        <div>
                                            <x-member::amount :value="$fundBefore" :currency="$currency" />
                                            →
                                            <x-member::amount :value="$fundAfter" :currency="$currency" />
                                        </div>
                                        @if (abs($fundDelta) > 0.00001)
                                            <div @class([
                                                'text-xs font-medium',
                                                'text-emerald-600 dark:text-emerald-400' => $fundDelta > 0,
                                                'text-rose-600 dark:text-rose-400' => $fundDelta < 0,
                                            ])>
                                                {{ $fundDelta > 0 ? '+' : '' }}{{ \App\Filament\Support\MoneyDisplay::format($fundDelta, $currency) ?? $fundDelta }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 align-top text-center tabular-nums text-gray-600 dark:text-gray-300">
                                        @if ($isContribution)
                                            —
                                        @else
                                            <x-member::amount :value="$outstandingBefore" :currency="$currency" />
                                            →
                                            <x-member::amount :value="$outstandingAfter" :currency="$currency" />
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 align-top text-center tabular-nums text-gray-600 dark:text-gray-300">
                                        @if ($isContribution)
                                            —
                                        @else
                                            <x-member::amount :value="$remainingBefore" :currency="$currency" />
                                            →
                                            <x-member::amount :value="$remainingAfter" :currency="$currency" />
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-3 py-4 text-center text-gray-500 dark:text-gray-400">
                                        {{ __('No simulation events yet.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <p class="text-center text-xs text-gray-400 dark:text-gray-500">
            {{ __('Intent vs live rules are documented for admins; this simulator follows lifecycle intent until production is aligned.') }}
        </p>
    @endif
</div>
