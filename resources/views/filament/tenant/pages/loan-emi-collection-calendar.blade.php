<x-filament-panels::page>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <x-filament::button color="gray" size="sm" wire:click="previousMonth" icon="heroicon-o-chevron-left">
                {{ __('Previous') }}
            </x-filament::button>
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $this->monthLabel() }}</h3>
            <x-filament::button color="gray" size="sm" wire:click="nextMonth" icon="heroicon-o-chevron-right"
                icon-position="after">
                {{ __('Next') }}
            </x-filament::button>
        </div>
        <div class="flex flex-wrap gap-3 text-xs text-gray-500 dark:text-gray-400">
            <span class="inline-flex items-center gap-1"><span
                    class="h-2 w-2 rounded-full bg-emerald-500"></span>{{ __('Collected') }}</span>
            <span class="inline-flex items-center gap-1"><span
                    class="h-2 w-2 rounded-full bg-amber-500"></span>{{ __('Pending') }}</span>
            <span class="inline-flex items-center gap-1"><span
                    class="h-2 w-2 rounded-full bg-rose-500"></span>{{ __('Overdue') }}</span>
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
    @endphp

    <div class="grid grid-cols-7 gap-2">
        @for ($i = 0; $i < $leadingBlanks; $i++)
            <div></div>
        @endfor

        @foreach ($grid as $day => $cell)
            <button type="button" wire:click="selectDate('{{ $cell['date'] }}')" @class([
                'min-h-20 rounded-xl border p-2 text-start transition',
                'border-sky-300 bg-sky-50 ring-2 ring-sky-200 dark:border-sky-700 dark:bg-sky-950/40 dark:ring-sky-800/50' => $selectedDate === $cell['date'],
                'border-gray-200 bg-white hover:border-sky-200 dark:border-white/10 dark:bg-gray-900/60' => $selectedDate !== $cell['date'],
            ])>
                <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ $day }}</div>
                @if ($cell['total'] > 0)
                    <div class="mt-2 space-y-1">
                        @if ($cell['collected'] > 0)
                            <span class="block h-1.5 rounded-full bg-emerald-500"></span>
                        @endif
                        @if ($cell['pending'] > 0)
                            <span class="block h-1.5 rounded-full bg-amber-500"></span>
                        @endif
                        @if ($cell['overdue'] > 0)
                            <span class="block h-1.5 rounded-full bg-rose-500"></span>
                        @endif
                        <p class="text-[10px] text-gray-500 dark:text-gray-400">
                            {{ trans_choice(':count EMI|:count EMIs', $cell['total'], ['count' => $cell['total']]) }}
                        </p>
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
                        {{ __('Due on :date', ['date' => \Carbon\Carbon::parse($selectedDate)->locale(app()->getLocale())->translatedFormat('d M Y')]) }}
                    </h4>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ trans_choice(':count installment|:count installments', $this->selectedDayInstallments()->count(), ['count' => $this->selectedDayInstallments()->count()]) }}
                    </p>
                </div>
                <button type="button" wire:click="clearSelectedDate"
                    class="text-xs font-medium text-sky-600 hover:underline dark:text-sky-400">
                    {{ __('Close') }}
                </button>
            </div>

            @if ($this->selectedDayInstallments()->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No EMIs due on this day.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="border-b border-gray-200 text-start text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2">{{ __('Member') }}</th>
                                <th class="px-3 py-2">{{ __('Loan') }}</th>
                                <th class="px-3 py-2">{{ __('Installment') }}</th>
                                <th class="px-3 py-2">{{ __('Amount') }}</th>
                                <th class="px-3 py-2">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($this->selectedDayInstallments() as $installment)
                                <tr wire:key="emi-day-{{ $installment->id }}">
                                    <td class="px-3 py-2">{{ $installment->loan?->member?->name ?? '—' }}</td>
                                    <td class="px-3 py-2">#{{ $installment->loan_id }}</td>
                                    <td class="px-3 py-2">{{ $installment->installment_number }}</td>
                                    <td class="px-3 py-2">
                                        {!! \App\Filament\Support\MoneyDisplay::html((float) $installment->amount, $this->currency())?->toHtml() !!}
                                    </td>
                                    <td class="px-3 py-2">
                                        <span @class([
                                            'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold uppercase',
                                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $installment->status === 'paid',
                                            'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $installment->status === 'pending',
                                            'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200' => $installment->status === 'overdue',
                                            'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => !in_array($installment->status, ['paid', 'pending', 'overdue'], true),
                                        ])>
                                            {{ $installment->status }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>