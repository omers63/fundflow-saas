@php
use App\Filament\Tenant\Support\BankClearingTabRegistry;

$bankTab = $bankTab ?? BankClearingTabRegistry::TAB_QUEUE;
$queueFilter = $queueFilter ?? BankClearingTabRegistry::FILTER_ALL;
@endphp

<section
    class="ff-bank-clearing-shell ff-ops-overview overflow-hidden rounded-xl border border-gray-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
    <header
        class="ff-ops-overview__header flex flex-wrap items-start justify-between gap-2 border-b border-gray-200/80 px-3 py-2.5 dark:border-white/10">
        <div class="min-w-0">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {{ __('Overview') }}
            </h2>
            <p class="mt-0.5 text-[11px] font-medium text-gray-900 dark:text-white">
                {{ __('Bank clearing workspace') }}
            </p>
            <p class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">
                {{ $this->getSubheading() }}
            </p>
        </div>
    </header>

    <div class="ff-ops-overview__body space-y-3 p-3">
        @if (\App\Support\BusinessDay::isOverridden())
            <p
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                {{ __('Business day is set to :business (calendar :calendar). Deposit and cash-out dates use the business day; CSV lines keep statement dates. Adjust match windows under Settings → Reconciliation if Auto-match finds nothing.', [
                    'business' => \App\Support\BusinessDay::today()->toFormattedDateString(),
                    'calendar' => \App\Support\BusinessDay::calendarToday()->toFormattedDateString(),
                ]) }}
            </p>
        @endif

        @include('filament.tenant.partials.audit-system.workspace-actions', [
            'class' => 'ff-audit-workspace-actions ff-bank-clearing-workspace-actions',
        ])

        @include('filament.tenant.partials.bank-clearing-tab-pills', [
            'bankTab' => $bankTab,
        ])

        <div class="min-w-0 space-y-3" wire:key="bank-clearing-workspace-{{ $bankTab }}-{{ $queueFilter }}">
            @if ($bankTab === BankClearingTabRegistry::TAB_QUEUE)
                @include('filament.tenant.partials.bank-clearing-queue-insights')
                @include('filament.tenant.partials.bank-clearing-queue-balances-toggle')
                @include('filament.tenant.partials.bank-clearing-workspace-shortcuts')
                @include('filament.tenant.partials.bank-clearing-queue-filters', [
                    'queueFilter' => $queueFilter,
                ])
            @elseif ($bankTab === BankClearingTabRegistry::TAB_LEDGER)
                @include('filament.tenant.widgets.partials.insights-kpi-strip', [
                    'kpis' => $this->getLedgerInsightKpis(),
                ])
            @elseif ($bankTab === BankClearingTabRegistry::TAB_HISTORY)
                @include('filament.tenant.widgets.partials.insights-kpi-strip', [
                    'kpis' => $this->getHistoryInsightKpis(),
                ])
                @include('filament.tenant.partials.bank-clearing-history-combined')
            @endif
        </div>
    </div>
</section>

{{-- History hides EmbeddedTable; HasTable pages omit page action modals otherwise. --}}
@include('filament.tenant.partials.page-workspace-action-modals')
