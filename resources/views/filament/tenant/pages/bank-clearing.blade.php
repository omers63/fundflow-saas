@php
    use App\Filament\Tenant\Support\BankClearingTabRegistry;

    $bankTab = $bankTab ?? BankClearingTabRegistry::TAB_QUEUE;
    $queueFilter = $queueFilter ?? BankClearingTabRegistry::FILTER_ALL;
@endphp

<section
    class="ff-bank-clearing-shell rounded-2xl border border-gray-200 bg-white px-5 py-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
    <header class="mb-4 border-b border-gray-100 pb-4 dark:border-white/10">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Bank clearing workspace') }}</h2>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ $this->getSubheading() }}
        </p>
    </header>

    @include('filament.tenant.partials.audit-system.workspace-actions', [
        'class' => 'ff-audit-workspace-actions ff-bank-clearing-workspace-actions mb-4',
    ])

    @include('filament.tenant.partials.bank-clearing-tab-pills', [
        'bankTab' => $bankTab,
    ])

    <div class="min-w-0 space-y-4" wire:key="bank-clearing-workspace-{{ $bankTab }}-{{ $queueFilter }}">
        @if ($bankTab === BankClearingTabRegistry::TAB_QUEUE)
            @include('filament.tenant.partials.bank-clearing-queue-insights')
            @include('filament.tenant.partials.bank-clearing-queue-balances-toggle')
            @include('filament.tenant.partials.bank-clearing-workspace-shortcuts')
            @include('filament.tenant.partials.bank-clearing-queue-filters', [
                'queueFilter' => $queueFilter,
            ])
        @elseif ($bankTab === BankClearingTabRegistry::TAB_HISTORY)
            @include('filament.tenant.partials.bank-clearing-history-combined')
        @endif
    </div>
</section>
