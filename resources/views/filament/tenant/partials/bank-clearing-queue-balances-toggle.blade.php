<div class="ff-bank-clearing-balances-toggle mb-3">
    <button type="button" wire:click="toggleQueueBalances"
        class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700 shadow-sm transition hover:border-sky-200 hover:text-sky-700 dark:border-white/10 dark:bg-gray-900/60 dark:text-gray-200 dark:hover:border-sky-800/40 dark:hover:text-sky-300">
        <x-heroicon-o-chart-bar class="h-4 w-4" />
        {{ $this->showQueueBalances ? __('Hide balances & trends') : __('Show balances & trends') }}
    </button>

    @if ($this->showQueueBalances)
        <div class="mt-3" wire:key="bank-clearing-full-insights">
            @livewire(\App\Filament\Tenant\Widgets\BankAccountsInsightsWidget::class)
        </div>
    @endif
</div>
