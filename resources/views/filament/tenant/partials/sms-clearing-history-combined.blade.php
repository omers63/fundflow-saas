@php
    use App\Filament\Tenant\Support\SmsClearingTabRegistry;
@endphp

<div class="ff-sms-clearing-history space-y-4">
    <section class="ff-sms-clearing-history-batches">
        <header class="mb-2">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Import batches') }}</h3>
            <p class="mt-1 text-[11px] text-gray-600 dark:text-gray-400">
                {{ __('Import batches with row counts, errors, and completion state.') }}
            </p>
        </header>

        @livewire(\App\Filament\Tenant\Widgets\SmsImportSessionsTableWidget::class, key('sms-clearing-history-batches'))
    </section>

    <section
        class="ff-sms-clearing-history-duplicates rounded-xl border border-gray-200 bg-gray-50/60 dark:border-white/10 dark:bg-gray-950/30">
        <button type="button" wire:click="toggleDuplicateHistory"
            class="flex w-full items-center justify-between gap-3 px-4 py-3 text-start">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Duplicate rows') }}</h3>
                <p class="mt-1 text-[11px] text-gray-600 dark:text-gray-400">
                    {{ __('Skipped duplicate SMS rows — read-only audit.') }}
                </p>
            </div>
            <x-heroicon-o-chevron-down @class([
                'h-5 w-5 shrink-0 text-gray-500 transition-transform dark:text-gray-400',
                'rotate-180' => $this->showDuplicateHistory,
            ]) />
        </button>

        @if ($this->showDuplicateHistory)
            <div class="border-t border-gray-200 px-1 pb-1 pt-2 dark:border-white/10"
                wire:key="sms-clearing-history-duplicates">
                @livewire(\App\Filament\Tenant\Widgets\SmsDuplicateArchiveTableWidget::class, key('sms-clearing-history-duplicates-table'))
            </div>
        @endif
    </section>
</div>