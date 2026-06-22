@php
    use App\Filament\Tenant\Support\BankClearingTabRegistry;
@endphp

<div class="ff-bank-clearing-history space-y-4">
    <section class="ff-bank-clearing-history-batches">
        <header class="mb-2">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Import batches') }}</h3>
            <p class="mt-1 text-[11px] text-gray-600 dark:text-gray-400">
                {{ __('Import batches with row counts, errors, and completion state.') }}
            </p>
        </header>

        @livewire(\App\Filament\Tenant\Widgets\BankImportBatchesTableWidget::class, key('bank-clearing-history-batches'))
    </section>

    <section
        class="ff-bank-clearing-history-closed rounded-xl border border-gray-200 bg-gray-50/60 dark:border-white/10 dark:bg-gray-950/30">
        <button type="button" wire:click="toggleClosedHistoryLines"
            class="flex w-full items-center justify-between gap-3 px-4 py-3 text-start">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Closed statement lines') }}</h3>
                <p class="mt-1 text-[11px] text-gray-600 dark:text-gray-400">
                    {{ __('Posted, duplicate, or ignored statement lines — read-only audit.') }}
                </p>
            </div>
            <x-heroicon-o-chevron-down @class([
                'h-5 w-5 shrink-0 text-gray-500 transition-transform dark:text-gray-400',
                'rotate-180' => $this->showClosedHistoryLines,
            ]) />
        </button>

        @if ($this->showClosedHistoryLines)
            <div class="border-t border-gray-200 px-1 pb-1 pt-2 dark:border-white/10"
                wire:key="bank-clearing-history-closed-lines">
                @livewire(\App\Filament\Tenant\Widgets\BankClosedStatementLinesTableWidget::class, key('bank-clearing-history-closed'))
            </div>
        @endif
    </section>
</div>