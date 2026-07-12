<x-filament-panels::page>
    <div class="mb-4 flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::button color="gray" size="sm" wire:click="previousMonth" icon="heroicon-o-chevron-left">
                {{ __('Previous') }}
            </x-filament::button>
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $this->monthLabel() }}</h3>
            <x-filament::button color="gray" size="sm" wire:click="nextMonth" icon="heroicon-o-chevron-right"
                icon-position="after">
                {{ __('Next') }}
            </x-filament::button>
        </div>
        <div
            class="grid grid-cols-1 gap-x-10 gap-y-2.5 text-xs text-gray-500 dark:text-gray-400 sm:grid-cols-2 sm:gap-x-12 lg:grid-cols-4 lg:gap-x-8">
            <span class="inline-flex items-center gap-2 whitespace-nowrap">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-sky-500"></span>
                {{ __('Contributions collected') }}
            </span>
            <span class="inline-flex items-center gap-2 whitespace-nowrap">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-emerald-500"></span>
                {{ __('EMI collected') }}
            </span>
            <span class="inline-flex items-center gap-2 whitespace-nowrap">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-amber-500"></span>
                {{ __('Contributions to collect') }}
            </span>
            <span class="inline-flex items-center gap-2 whitespace-nowrap">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-rose-500"></span>
                {{ __('EMI to collect') }}
            </span>
        </div>
    </div>

    <div
        class="grid grid-cols-7 gap-2 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
        @foreach ([__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')] as $weekday)
            <div class="py-2">{{ $weekday }}</div>
        @endforeach
    </div>

    @php
        $firstDay = \Carbon\Carbon::create($calendarYear, $calendarMonth, 1);
        $leadingBlanks = $firstDay->dayOfWeek;
        $grid = $this->monthGrid();
        $currency = $this->currency();
    @endphp

    <div class="grid grid-cols-7 gap-2">
        @for ($i = 0; $i < $leadingBlanks; $i++)
            <div></div>
        @endfor

        @foreach ($grid as $day => $cell)
            @php
                $hasActivity = ($cell['paid_on_contribution'] ?? 0) > 0
                    || ($cell['paid_on_emi'] ?? 0) > 0
                    || ($cell['to_collect_contribution'] ?? 0) > 0
                    || ($cell['to_collect_emi'] ?? 0) > 0;
            @endphp
            <button type="button" wire:click="selectDate('{{ $cell['date'] }}')" @class([
                'min-h-24 rounded-xl border p-2 text-start transition',
                'border-sky-300 bg-sky-50 ring-2 ring-sky-200 dark:border-sky-700 dark:bg-sky-950/40 dark:ring-sky-800/50' => $selectedDate === $cell['date'],
                'border-gray-200 bg-white hover:border-sky-200 dark:border-white/10 dark:bg-gray-900/60' => $selectedDate !== $cell['date'],
            ])>
                <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ $day }}</div>
                @if ($hasActivity)
                    <div class="mt-2 space-y-1">
                        <div class="flex flex-col gap-0.5">
                            @if (($cell['paid_on_contribution'] ?? 0) > 0)
                                <span class="block h-1.5 rounded-full bg-sky-500"></span>
                            @endif
                            @if (($cell['paid_on_emi'] ?? 0) > 0)
                                <span class="block h-1.5 rounded-full bg-emerald-500"></span>
                            @endif
                            @if (($cell['to_collect_contribution'] ?? 0) > 0)
                                <span class="block h-1.5 rounded-full bg-amber-500"></span>
                            @endif
                            @if (($cell['to_collect_emi'] ?? 0) > 0)
                                <span class="block h-1.5 rounded-full bg-rose-500"></span>
                            @endif
                        </div>
                        <div class="space-y-0.5">
                            @if (($cell['paid_on_contribution'] ?? 0) > 0)
                                <p class="text-[10px] font-medium text-sky-600 dark:text-sky-400">
                                    {{ trans_choice(':count contribution collected|:count contributions collected', $cell['paid_on_contribution'], ['count' => $cell['paid_on_contribution']]) }}
                                    @if (($cell['paid_on_contribution_amount'] ?? 0) > 0)
                                        ·
                                        {!! \App\Filament\Support\MoneyDisplay::html($cell['paid_on_contribution_amount'], $currency)?->toHtml() !!}
                                    @endif
                                </p>
                            @endif
                            @if (($cell['paid_on_emi'] ?? 0) > 0)
                                <p class="text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
                                    {{ trans_choice(':count EMI collected|:count EMIs collected', $cell['paid_on_emi'], ['count' => $cell['paid_on_emi']]) }}
                                    @if (($cell['paid_on_emi_amount'] ?? 0) > 0)
                                        ·
                                        {!! \App\Filament\Support\MoneyDisplay::html($cell['paid_on_emi_amount'], $currency)?->toHtml() !!}
                                    @endif
                                </p>
                            @endif
                            @if (($cell['to_collect_contribution'] ?? 0) > 0)
                                <p class="text-[10px] font-medium text-amber-600 dark:text-amber-400">
                                    {{ trans_choice(':count contribution to collect|:count contributions to collect', $cell['to_collect_contribution'], ['count' => $cell['to_collect_contribution']]) }}
                                    @if (($cell['to_collect_contribution_amount'] ?? 0) > 0)
                                        ·
                                        {!! \App\Filament\Support\MoneyDisplay::html($cell['to_collect_contribution_amount'], $currency)?->toHtml() !!}
                                    @endif
                                </p>
                            @endif
                            @if (($cell['to_collect_emi'] ?? 0) > 0)
                                <p class="text-[10px] font-medium text-rose-600 dark:text-rose-400">
                                    {{ trans_choice(':count EMI to collect|:count EMIs to collect', $cell['to_collect_emi'], ['count' => $cell['to_collect_emi']]) }}
                                    @if (($cell['to_collect_emi_amount'] ?? 0) > 0)
                                        ·
                                        {!! \App\Filament\Support\MoneyDisplay::html($cell['to_collect_emi_amount'], $currency)?->toHtml() !!}
                                    @endif
                                </p>
                            @endif
                        </div>
                    </div>
                @endif
            </button>
        @endforeach
    </div>

    @if ($selectedDate)
        <div class="mt-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ __('Collections for :date', ['date' => \Carbon\Carbon::parse($selectedDate)->locale(app()->getLocale())->translatedFormat('d M Y')]) }}
                    </h4>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ trans_choice(':count item|:count items', $this->selectedDayItemCount(), ['count' => $this->selectedDayItemCount()]) }}
                    </p>
                </div>
                <button type="button" wire:click="clearSelectedDate"
                    class="text-xs font-medium text-sky-600 hover:underline dark:text-sky-400">
                    {{ __('Close') }}
                </button>
            </div>

            @if ($this->selectedDayItemCount() === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('No contributions or EMIs due or posted on this day.') }}
                </p>
            @else
                @if ($this->selectedDayContributions()->isNotEmpty())
                    <div class="mb-6">
                        <h5
                            class="mb-3 inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">
                            <span class="h-2 w-2 rounded-full bg-sky-500"></span>
                            {{ __('Contributions') }}
                        </h5>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="border-b border-gray-200 text-start text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                    <tr>
                                        <th class="px-3 py-2">{{ __('Member') }}</th>
                                        <th class="px-3 py-2">{{ __('Period') }}</th>
                                        <th class="px-3 py-2">{{ __('Amount') }}</th>
                                        <th class="px-3 py-2">{{ __('Collection') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                    @foreach ($this->selectedDayContributions() as $contribution)
                                                <tr wire:key="contribution-day-{{ $contribution->id }}">
                                                    <td class="px-3 py-2">{{ $contribution->member?->name ?? '—' }}</td>
                                                    <td class="px-3 py-2">
                                                        {{ \App\Support\MemberDateDisplay::format($contribution->period, 'M Y') ?? '—' }}
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        {!! \App\Filament\Support\MoneyDisplay::html((float) $contribution->amount, $this->currency())?->toHtml() !!}
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <span
                                                            class="inline-flex rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold uppercase text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">
                                                            {{ __('Paid on :date', [
                                            'date' => \App\Support\BusinessDayDisplay::formatDateTime($contribution->posted_at ?? $contribution->paid_at, 'd M Y g:i A') ?? '—',
                                        ]) }}
                                                        </span>
                                                    </td>
                                                </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @if ($this->selectedDayEmis()->isNotEmpty())
                    <div>
                        <h5
                            class="mb-3 inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            {{ __('EMI repayments') }}
                        </h5>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="border-b border-gray-200 text-start text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                    <tr>
                                        <th class="px-3 py-2">{{ __('Member') }}</th>
                                        <th class="px-3 py-2">{{ __('Loan') }}</th>
                                        <th class="px-3 py-2">{{ __('Installment') }}</th>
                                        <th class="px-3 py-2">{{ __('Amount') }}</th>
                                        <th class="px-3 py-2">{{ __('Due') }}</th>
                                        <th class="px-3 py-2">{{ __('Collection') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                    @foreach ($this->selectedDayEmis() as $installment)
                                        @php
                                            $isPaid = $installment->status === 'paid' && $installment->paid_at !== null;
                                        @endphp
                                        <tr wire:key="emi-day-{{ $installment->id }}">
                                            <td class="px-3 py-2">{{ $installment->loan?->member?->name ?? '—' }}</td>
                                            <td class="px-3 py-2">#{{ $installment->loan_id }}</td>
                                            <td class="px-3 py-2">{{ $installment->installment_number }}</td>
                                            <td class="px-3 py-2">
                                                {!! \App\Filament\Support\MoneyDisplay::html((float) $installment->amount, $this->currency())?->toHtml() !!}
                                            </td>
                                            <td class="px-3 py-2">
                                                {{ \App\Support\MemberDateDisplay::format($installment->due_date, 'd M Y') ?? '—' }}
                                            </td>
                                            <td class="px-3 py-2">
                                                @if ($isPaid)
                                                                <span
                                                                    class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold uppercase text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">
                                                                    {{ __('Paid on :date', [
                                                        'date' => \App\Support\BusinessDayDisplay::formatDateTime($installment->paid_at, 'd M Y g:i A') ?? '—',
                                                    ]) }}
                                                                </span>
                                                @else
                                                    <span
                                                        class="inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold uppercase text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">
                                                        {{ __('To be collected') }}
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    @endif
</x-filament-panels::page>