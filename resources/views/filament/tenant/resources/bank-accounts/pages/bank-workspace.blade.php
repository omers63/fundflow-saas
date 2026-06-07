<section
    class="rounded-2xl border border-gray-200 bg-white px-5 py-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Workspace') }}</h3>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Switch between bank statement and SMS channels. Parsing templates are managed under Settings.') }}
            </p>
        </div>
    </div>

    <x-filament::tabs class="mb-2 justify-center">
        <x-filament::tabs.item :active="$channel === 'bank'" icon="heroicon-o-building-library" tag="button" type="button"
            wire:click="setChannel('bank')">
            {{ __('Bank') }}
        </x-filament::tabs.item>
        <x-filament::tabs.item :active="$channel === 'sms'" icon="heroicon-o-device-phone-mobile" tag="button"
            type="button" wire:click="setChannel('sms')">
            {{ __('SMS') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    @if ($channel === 'sms')
        <x-filament::tabs class="mb-4 mt-0 justify-center">
            <x-filament::tabs.item :active="$smsSubTab === 'transactions'" icon="heroicon-o-arrows-right-left" tag="button"
                type="button" wire:click="setSmsSubTab('transactions')">
                {{ __('Transactions') }}
            </x-filament::tabs.item>
            <x-filament::tabs.item :active="$smsSubTab === 'history'" icon="heroicon-o-clock" tag="button" type="button"
                wire:click="setSmsSubTab('history')">
                {{ __('History') }}
            </x-filament::tabs.item>
        </x-filament::tabs>

        @if ($smsSubTab === 'transactions')
            <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Review parsed SMS transactions and post verified rows to member cash.') }}
            </p>
            @livewire(\App\Filament\Tenant\Widgets\SmsTransactionsTableWidget::class, key('bank-accounts-sms-transactions'))
        @else
            <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Monitor SMS import batches with counts, errors, and completion state.') }}
            </p>
            @livewire(\App\Filament\Tenant\Widgets\SmsImportSessionsTableWidget::class, key('bank-accounts-sms-history'))
        @endif
    @endif
</section>