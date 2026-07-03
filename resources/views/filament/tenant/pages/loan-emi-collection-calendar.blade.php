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
                    class="h-2 w-2 rounded-full bg-emerald-500"></span>{{ __('Paid on') }}</span>
            <span class="inline-flex items-center gap-1"><span
                    class="h-2 w-2 rounded-full bg-rose-500"></span>{{ __('To be collected') }}</span>
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
            <button type="button" wire:click="selectDate('{{ $cell['date'] }}')" @class([
                'min-h-20 rounded-xl border p-2 text-start transition',
                'border-sky-300 bg-sky-50 ring-2 ring-sky-200 dark:border-sky-700 dark:bg-sky-950/40 dark:ring-sky-800/50' => $selectedDate === $cell['date'],
                'border-gray-200 bg-white hover:border-sky-200 dark:border-white/10 dark:bg-gray-900/60' => $selectedDate !== $cell['date'],
            ])>
                <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ $day }}</div>
                @if ($cell['to_collect'] > 0 || $cell['paid_on'] > 0)
                    <div class="mt-2 space-y-1">
                        @if ($cell['paid_on'] > 0)
                            <span class="block h-1.5 rounded-full bg-emerald-500"></span>
                        @endif
                        @if ($cell['to_collect'] > 0)
                            <span class="block h-1.5 rounded-full bg-rose-500"></span>
                        @endif
                        <div class="space-y-0.5">
                            @if ($cell['paid_on'] > 0)
                                <p class="text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
                                    {{ trans_choice(':count paid on|:count paid on', $cell['paid_on'], ['count' => $cell['paid_on']]) }}
                                    @if ($cell['paid_on_amount'] > 0)
                                        · {!! \App\Filament\Support\MoneyDisplay::html($cell['paid_on_amount'], $currency)?->toHtml() !!}
                                    @endif
                                </p>
                            @endif
                            @if ($cell['to_collect'] > 0)
                                <p class="text-[10px] font-medium text-rose-600 dark:text-rose-400">
                                    {{ trans_choice(':count to collect|:count to collect', $cell['to_collect'], ['count' => $cell['to_collect']]) }}
                                    @if ($cell['to_collect_amount'] > 0)
                                        · {!! \App\Filament\Support\MoneyDisplay::html($cell['to_collect_amount'], $currency)?->toHtml() !!}
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
                        {{ __('EMIs for :date', ['date' => \Carbon\Carbon::parse($selectedDate)->locale(app()->getLocale())->translatedFormat('d M Y')]) }}
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
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No EMIs due or posted on this day.') }}</p>
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
                                <th class="px-3 py-2">{{ __('Due') }}</th>
                                <th class="px-3 py-2">{{ __('Collection') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($this->selectedDayInstallments() as $installment)
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
                                                'date' => \App\Support\MemberDateDisplay::format($installment->paid_at, 'd M Y g:i A') ?? '—',
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
            @endif
        </div>
    @endif
</x-filament-panels::page>
