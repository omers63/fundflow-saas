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

    <div class="ff-tenant-tab-pills mb-4 flex flex-wrap justify-center gap-2">
        <button type="button" wire:click="setChannel('bank')" @class([
            'ff-tenant-tab-pills__item',
            'ff-tenant-tab-pills__item--active' => $channel === 'bank',
        ])>
            {{ __('Bank') }}
        </button>
        <button type="button" wire:click="setChannel('sms')" @class([
            'ff-tenant-tab-pills__item',
            'ff-tenant-tab-pills__item--active' => $channel === 'sms',
        ])>
            {{ __('SMS') }}
        </button>
    </div>

    @if ($channel === 'sms')
        <div class="ff-tenant-tab-pills mb-4 flex flex-wrap justify-center gap-2">
            <button type="button" wire:click="setSmsSubTab('transactions')" @class([
                'ff-tenant-tab-pills__item',
                'ff-tenant-tab-pills__item--active' => $smsSubTab === 'transactions',
            ])>
                {{ __('Transactions') }}
            </button>
            <button type="button" wire:click="setSmsSubTab('history')" @class([
                'ff-tenant-tab-pills__item',
                'ff-tenant-tab-pills__item--active' => $smsSubTab === 'history',
            ])>
                {{ __('History') }}
            </button>
        </div>

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