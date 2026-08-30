@php
$sim = $this->simulation;
$simActive = is_array($sim) && ($sim['status'] ?? '') === \App\Services\MemberLoanLifecycleSimulator::STATUS_ACTIVE;
$simClosed = is_array($sim) && in_array($sim['status'] ?? '', [
    \App\Services\MemberLoanLifecycleSimulator::STATUS_PAID,
    \App\Services\MemberLoanLifecycleSimulator::STATUS_FULLY_SETTLED,
], true);
$scheduleRows = is_array($sim['schedule_rows'] ?? null) ? $sim['schedule_rows'] : [];
$moneyHtml = fn(float|int|string|null $value): string => \App\Filament\Support\MoneyDisplay::html($value, $currency)?->toHtml() ?? e('—');
@endphp

<div class="ff-member-loan-sim space-y-6">
    <x-member::panel :title="__('Lifecycle simulator')" collapsible>
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

    @if (!is_array($sim))
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

            <div class="space-y-3 border-b border-gray-100 px-4 py-4 sm:px-5 dark:border-gray-700">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
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
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg bg-emerald-50/80 p-3 ring-1 ring-emerald-200/80 dark:bg-emerald-950/30 dark:ring-emerald-800/60">
                        <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{{ __('Projected fund at start') }}</p>
                        <p class="mt-1 text-lg font-bold text-emerald-900 dark:text-emerald-100">
                            <x-member::amount :value="$sim['pre_loan_fund']" :currency="$currency" />
                        </p>
                    </div>
                    <div class="rounded-lg bg-amber-50/80 p-3 ring-1 ring-amber-200/80 dark:bg-amber-950/30 dark:ring-amber-800/60">
                        <p class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">{{ __('Remaining maturity') }}</p>
                        <p class="mt-1 text-lg font-bold text-amber-900 dark:text-amber-100">
                            <x-member::amount :value="$sim['remaining_maturity']" :currency="$currency" />
                        </p>
                        @if ($simActive)
                            <p class="mt-0.5 text-xs text-amber-700/80 dark:text-amber-300/80">
                                {{ __(':count month(s) left', ['count' => (int) ($sim['remaining_months'] ?? 0)]) }}
                            </p>
                        @endif
                    </div>
                    <div class="rounded-lg bg-indigo-50/80 p-3 ring-1 ring-indigo-200/80 dark:bg-indigo-950/30 dark:ring-indigo-800/60">
                        <p class="text-xs uppercase tracking-wide text-indigo-700 dark:text-indigo-300">{{ __('Simulated fund') }}</p>
                        <p class="mt-1 text-lg font-bold text-indigo-900 dark:text-indigo-100">
                            <x-member::amount :value="$sim['fund_balance']" :currency="$currency" />
                        </p>
                    </div>
                    <div class="rounded-lg bg-sky-50/80 p-3 ring-1 ring-sky-200/80 dark:bg-sky-950/30 dark:ring-sky-800/60">
                        <p class="text-xs uppercase tracking-wide text-sky-700 dark:text-sky-300">{{ __('Simulated cash') }}</p>
                        <p class="mt-1 text-lg font-bold text-sky-900 dark:text-sky-100">
                            <x-member::amount :value="$sim['cash_balance'] ?? 0" :currency="$currency" />
                        </p>
                        @if (((float) ($sim['excess_to_cash'] ?? 0)) > 0.00001)
                            <p class="mt-0.5 text-xs text-sky-700/80 dark:text-sky-300/80">
                                {!! __('Includes excess transferred at disbursement (:amount)', [
            'amount' => $moneyHtml((float) $sim['excess_to_cash']),
        ]) !!}
                            </p>
                        @endif
                    </div>
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

                        <div class="ff-member-loan-sim-pay-actions mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <div class="flex flex-col gap-3">
                                <div>
                                    <p class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Regular payment') }}</p>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {!! __('Always pays the installment amount (:amount).', [
            'amount' => $moneyHtml((float) ($sim['min_installment'] ?? 0)),
        ]) !!}
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
                                    <p class="mt-1 text-xs font-medium text-gray-700 dark:text-gray-200">
                                        {!! __('Amount needed for full maturity: :amount', [
            'amount' => $moneyHtml((float) ($sim['remaining_maturity'] ?? 0)),
        ]) !!}
                                    </p>
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
                                        {!! __('Pay :amount to close now.', [
            'amount' => $moneyHtml((float) ($sim['full_settlement_amount'] ?? 0)),
        ]) !!}
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

            <div class="border-b border-gray-100 dark:border-gray-700">
                <div class="ff-member-loan-sim-schedule-stats px-4 py-4 sm:px-5">
                    <div
                        wire:key="sim-schedule-count-{{ (int) ($sim['schedule_count'] ?? 0) }}-{{ $this->simulationScheduleFlashKey }}"
                        class="ff-member-loan-sim-stat-card ff-member-loan-sim-stat-card--cycles bg-slate-50/90 ring-1 ring-slate-200/90 dark:bg-slate-950/30 dark:ring-slate-700/60"
                    >
                        <p class="ff-member-loan-sim-stat-card__label text-slate-600 dark:text-slate-300">
                            {{ __('total cycle(s)') }}
                        </p>
                        <p class="ff-member-loan-sim-stat-card__value text-slate-950 dark:text-slate-100">
                            {{ (int) ($sim['schedule_count'] ?? 0) }}
                        </p>
                    </div>
                    <div
                        wire:key="sim-pending-count-{{ (int) ($sim['pending_count'] ?? 0) }}-{{ $this->simulationPendingFlashKey }}"
                        class="ff-member-loan-sim-stat-card ff-member-loan-sim-stat-card--pending bg-primary-50/90 ring-1 ring-primary-200/90 dark:bg-primary-950/30 dark:ring-primary-700/60"
                    >
                        <p class="ff-member-loan-sim-stat-card__label text-primary-700 dark:text-primary-300">
                            {{ __('pending installment(s)') }}
                        </p>
                        <p class="ff-member-loan-sim-stat-card__value text-primary-950 dark:text-primary-100">
                            {{ (int) ($sim['pending_count'] ?? 0) }}
                        </p>
                    </div>
                    <div
                        wire:key="sim-projected-maturity-{{ $sim['expected_maturity_date'] ?? 'none' }}-{{ $this->simulationMaturityFlashKey }}"
                        class="ff-member-loan-sim-stat-card ff-member-loan-sim-stat-card--maturity bg-violet-50/90 ring-1 ring-violet-200/90 dark:bg-violet-950/30 dark:ring-violet-700/60"
                    >
                        <p class="ff-member-loan-sim-stat-card__label text-violet-700 dark:text-violet-300">
                            {{ __('Projected loan maturity date') }}
                        </p>
                        <p class="ff-member-loan-sim-stat-card__value text-violet-950 dark:text-violet-100">
                            @if (filled($sim['expected_maturity_date'] ?? null))
                                {{ \Carbon\Carbon::parse($sim['expected_maturity_date'])->locale(app()->getLocale())->translatedFormat('j M Y') }}
                            @else
                                —
                            @endif
                        </p>
                    </div>
                    <div class="ff-member-loan-sim-schedule-stats__action">
                        <x-filament::button
                            type="button"
                            color="danger"
                            size="sm"
                            icon="heroicon-o-arrow-path"
                            wire:click="startSimulationFromEstimate"
                        >
                            {{ __('Reset simulation') }}
                        </x-filament::button>
                    </div>
                </div>

                <details class="group" open>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 marker:content-none sm:px-5 dark:border-gray-700 [&::-webkit-details-marker]:hidden">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('Updating schedule') }}
                            </p>
                            <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">
                                {{ __('Cycles and due dates refresh as you apply payments or settlement.') }}
                            </p>
                        </div>
                        <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-180 dark:text-gray-500" />
                    </summary>

                    <div class="px-4 pb-4 sm:px-5">
                        <div class="max-h-80 overflow-y-auto rounded-lg ring-1 ring-gray-200 dark:ring-gray-700">
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
                                    @forelse ($scheduleRows as $row)
                                                                        @php
                                        $kind = (string) ($row['kind'] ?? 'pending');
                                        $days = $row['days_until_due'] ?? null;
                                                                        @endphp
                                                                        <tr
                                                                            @class([
                                                                                'border-b border-gray-100 transition-colors last:border-b-0 dark:border-gray-700',
                                                                                'bg-violet-50/70 dark:bg-violet-950/20' => $kind === 'grace',
                                                                                'bg-amber-50/70 dark:bg-amber-950/20' => $kind === 'contribution_due',
                                                                                'bg-emerald-50/50 dark:bg-emerald-950/20' => $kind === 'contribution_paid',
                                                                                'bg-emerald-50/70 dark:bg-emerald-950/25' => $kind === 'paid',
                                                                                'bg-primary-50/70 dark:bg-primary-950/25' => in_array($kind, ['rolled_up', 'skipped', 'dropped'], true),
                                                                                'bg-amber-50/70 dark:bg-amber-950/25' => $kind === 'cancelled',
                                                                            ])
                                                                        >
                                                                            <td class="whitespace-nowrap px-3 py-2 align-top text-start font-semibold tabular-nums text-gray-700 dark:text-gray-200">
                                                                                @if (in_array($kind, ['grace', 'contribution_due', 'contribution_paid'], true))
                                                                                    —
                                                                                @else
                                                                                    {{ $row['number'] ?? '—' }}
                                                                                @endif
                                                                            </td>
                                                                            <td class="px-3 py-2 align-top text-start">
                                                                                <div class="flex flex-wrap items-center gap-1.5">
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
                                                                                    <span class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $row['cycle_label'] ?? '—' }}</span>
                                                                                </div>
                                                                            </td>
                                                                            <td class="whitespace-nowrap px-3 py-2 align-top text-start text-sm text-gray-500 dark:text-gray-400">
                                                                                <p class="tabular-nums">{{ $row['due_label'] ?? ($row['due_date'] ?? '—') }}</p>
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
                                                                            </td>
                                                                            <td @class([
                                                                                'whitespace-nowrap px-3 py-2 align-top text-end text-sm font-semibold tabular-nums',
                                                                                'text-gray-400 dark:text-gray-500' => in_array($kind, ['skipped', 'grace', 'contribution_paid'], true)
                                                                                    || ($kind === 'cancelled' && ((float) ($row['amount'] ?? 0)) <= 0.00001),
                                                                                'text-gray-900 dark:text-white' => !in_array($kind, ['skipped', 'grace', 'contribution_paid'], true)
                                                                                    && !($kind === 'cancelled' && ((float) ($row['amount'] ?? 0)) <= 0.00001),
                                                                            ])>
                                                                                @if (in_array($kind, ['grace', 'contribution_paid'], true))
                                                                                    —
                                                                                @elseif ($kind === 'skipped' || ($kind === 'cancelled' && ((float) ($row['amount'] ?? 0)) <= 0.00001))
                                                                                    {{ __('No EMI') }}
                                                                                @else
                                                                                    <x-member::amount :value="$row['amount'] ?? 0" :currency="$currency" />
                                                                                @endif
                                                                            </td>
                                                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                                {{ __('No schedule rows.') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>
            </div>

            <details class="group border-b border-gray-100 dark:border-gray-700" open>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-4 marker:content-none sm:px-5 [&::-webkit-details-marker]:hidden">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Simulation history') }}</p>
                    <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-180 dark:text-gray-500" />
                </summary>
                <div class="px-4 pb-4 sm:px-5">
                    <div class="ff-member-loan-sim-history">
                        <table class="ff-member-loan-sim-history__table min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="sticky top-0 bg-gray-50 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-800/80 dark:text-gray-400">
                                <tr>
                                    <th scope="col" class="px-3 py-2 text-center">{{ __('Date') }}</th>
                                    <th scope="col" class="px-3 py-2 text-center">{{ __('Event') }}</th>
                                    <th scope="col" class="px-3 py-2 text-center">{{ __('Amount') }}</th>
                                    <th scope="col" class="px-3 py-2 text-center">{{ __('Fund') }}</th>
                                    <th scope="col" class="px-3 py-2 text-center">{{ __('Cash') }}</th>
                                    <th scope="col" class="px-3 py-2 text-center">{{ __('Outstanding fund portion') }}</th>
                                    <th scope="col" class="px-3 py-2 text-center">{{ __('Remaining maturity') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @forelse (array_reverse($sim['history'] ?? []) as $event)
                                    <tr class="bg-white dark:bg-gray-800/40">
                                        <td
                                            data-label="{{ __('Date') }}"
                                            class="px-3 py-2 align-top text-center tabular-nums text-gray-600 dark:text-gray-300"
                                        >
                                            {{ $event['at_label'] ?? ($event['at'] ?? '—') }}
                                        </td>
                                        <td
                                            data-label="{{ __('Event') }}"
                                            class="ff-member-loan-sim-history__event px-3 py-2 align-top text-center font-medium text-gray-800 dark:text-gray-100"
                                        >
                                            {{ $event['label'] ?? '—' }}
                                        </td>
                                        <td data-label="{{ __('Amount') }}" class="px-3 py-2 align-top text-center tabular-nums">
                                            @php $historyAmount = (float) ($event['amount'] ?? 0); @endphp
                                            @if (abs($historyAmount) > 0.00001)
                                                <x-member::amount :value="$historyAmount" :currency="$currency" signed />
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td data-label="{{ __('Fund') }}" class="px-3 py-2 align-top text-center tabular-nums">
                                            <x-member::amount :value="$event['fund_balance'] ?? 0" :currency="$currency" signed />
                                        </td>
                                        <td data-label="{{ __('Cash') }}" class="px-3 py-2 align-top text-center tabular-nums">
                                            <x-member::amount :value="$event['cash_balance'] ?? 0" :currency="$currency" signed />
                                        </td>
                                        <td data-label="{{ __('Outstanding fund portion') }}" class="ff-member-loan-sim-history__owed px-3 py-2 align-top text-center tabular-nums">
                                            @php $outstandingFundPortion = (float) ($event['outstanding_fund_portion'] ?? 0); @endphp
                                            <x-member::amount
                                                :value="$outstandingFundPortion"
                                                :currency="$currency"
                                                @class(['ff-member-amount--danger' => $outstandingFundPortion > 0.00001])
                                            />
                                        </td>
                                        <td data-label="{{ __('Remaining maturity') }}" class="ff-member-loan-sim-history__owed px-3 py-2 align-top text-center tabular-nums">
                                            @php $remainingMaturity = (float) ($event['remaining_maturity'] ?? 0); @endphp
                                            <x-member::amount
                                                :value="$remainingMaturity"
                                                :currency="$currency"
                                                @class(['ff-member-amount--danger' => $remainingMaturity > 0.00001])
                                            />
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="ff-member-loan-sim-history__empty px-3 py-4 text-center text-gray-500 dark:text-gray-400">
                                            {{ __('No simulation events yet.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </details>

            @if ($simClosed)
                <div class="space-y-3 border-t border-gray-100 px-4 py-4 sm:px-5 dark:border-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('After close') }}</p>
                        <x-member::chip :variant="($sim['eligible_for_new_loan'] ?? false) ? 'green' : 'amber'">
                            {{ ($sim['eligible_for_new_loan'] ?? false) ? __('Eligible for new loan') : __('Not yet eligible') }}
                        </x-member::chip>
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="rounded-lg bg-indigo-50/80 p-3 ring-1 ring-indigo-200/80 dark:bg-indigo-950/30 dark:ring-indigo-800/60">
                            <p class="text-xs uppercase tracking-wide text-indigo-700 dark:text-indigo-300">{{ __('Simulated fund') }}</p>
                            <p class="mt-1 text-lg font-bold text-indigo-900 dark:text-indigo-100">
                                <x-member::amount :value="$sim['fund_balance']" :currency="$currency" />
                            </p>
                        </div>
                        <div class="rounded-lg bg-sky-50/80 p-3 ring-1 ring-sky-200/80 dark:bg-sky-950/30 dark:ring-sky-800/60">
                            <p class="text-xs uppercase tracking-wide text-sky-700 dark:text-sky-300">{{ __('Simulated cash') }}</p>
                            <p class="mt-1 text-lg font-bold text-sky-900 dark:text-sky-100">
                                <x-member::amount :value="$sim['cash_balance'] ?? 0" :currency="$currency" />
                            </p>
                        </div>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {!! __('Need fund ≥ :amount (:percent% of tier ceiling). Deposit cash first, then apply any allowable contribution amount from cash.', [
            'amount' => $moneyHtml((float) $sim['eligibility_amt']),
            'percent' => $this->eligibilityPct > 0 ? round($this->eligibilityPct * 100) : '—',
        ]) !!}
                    </p>
                    @php
        $afterCloseMinDate = filled($sim['expected_maturity_date'] ?? null)
            ? (string) $sim['expected_maturity_date']
            : null;
        $showAfterCloseContribution = !($sim['eligible_for_new_loan'] ?? false);
                    @endphp
                    <div class="space-y-3">
                        <div class="min-w-0 w-full sm:max-w-xs">
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="loan-sim-after-close-date">
                                {{ __('Date') }}
                            </label>
                            <input
                                id="loan-sim-after-close-date"
                                type="date"
                                wire:model.live="simulationAfterCloseDate"
                                @if ($afterCloseMinDate) min="{{ $afterCloseMinDate }}" @endif
                                class="block h-10 w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>

                        <div @class([
            'grid grid-cols-1 gap-3',
            'sm:grid-cols-2' => $showAfterCloseContribution,
        ])>
                            @if ($showAfterCloseContribution)
                                <div class="min-w-0">
                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="loan-sim-contribution">
                                        {{ __('Contribution amount') }}
                                    </label>
                                    <div class="ff-member-loan-sim-after-close-field">
                                        <select
                                            id="loan-sim-contribution"
                                            wire:model="simulationContributionAmount"
                                            class="block h-10 min-w-0 w-full flex-1 rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        >
                                            @foreach ($this->projectedContributionOptions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <x-filament::button type="button" color="primary" size="sm" wire:click="applySimulationContribution">
                                            {{ __('Apply contribution cycle') }}
                                        </x-filament::button>
                                    </div>
                                </div>
                            @endif
                            <div class="min-w-0">
                                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="loan-sim-cash-deposit">
                                    {{ __('Cash deposit amount') }}
                                </label>
                                <div class="ff-member-loan-sim-after-close-field">
                                    <input
                                        id="loan-sim-cash-deposit"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        wire:model="simulationCashDepositAmount"
                                        class="block h-10 min-w-0 w-full flex-1 rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                    <x-filament::button type="button" color="success" size="sm" wire:click="applySimulationCashDeposit">
                                        {{ __('Apply cash deposit') }}
                                    </x-filament::button>
                                </div>
                            </div>
                        </div>
                    </div>
                    @if ($afterCloseMinDate)
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Not before loan maturity (:date).', [
                'date' => \Carbon\Carbon::parse($afterCloseMinDate)->locale(app()->getLocale())->translatedFormat('j M Y'),
            ]) }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <p class="text-center text-xs text-gray-400 dark:text-gray-500">
            {{ __('Intent vs live rules are documented for admins; this simulator follows lifecycle intent until production is aligned.') }}
        </p>
    @endif
</div>
