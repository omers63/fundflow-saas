@php
use App\Filament\Tenant\Support\SmsClearingTabRegistry;

$smsTab = $smsTab ?? SmsClearingTabRegistry::TAB_QUEUE;
$queueFilter = $queueFilter ?? SmsClearingTabRegistry::FILTER_ALL;
@endphp

<section
    class="ff-sms-clearing-shell ff-ops-overview overflow-hidden rounded-xl border border-gray-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
    <header
        class="ff-ops-overview__header flex flex-wrap items-start justify-between gap-2 border-b border-gray-200/80 px-3 py-2.5 dark:border-white/10">
        <div class="min-w-0">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {{ __('Overview') }}
            </h2>
            <p class="mt-0.5 text-[11px] font-medium text-gray-900 dark:text-white">
                {{ __('Bank SMS clearing') }}
            </p>
            <p class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">
                {{ $this->getSubheading() }}
            </p>
        </div>
    </header>

    <div class="ff-ops-overview__body space-y-3 p-3">
        @include('filament.tenant.partials.audit-system.workspace-actions', [
            'class' => 'ff-audit-workspace-actions ff-sms-clearing-workspace-actions',
        ])

        @include('filament.tenant.partials.sms-clearing-tab-pills', [
            'smsTab' => $smsTab,
        ])

        <div class="min-w-0 space-y-3" wire:key="sms-clearing-workspace-{{ $smsTab }}-{{ $queueFilter }}">
            @if ($smsTab === SmsClearingTabRegistry::TAB_QUEUE)
                @include('filament.tenant.partials.sms-clearing-queue-insights')
                @include('filament.tenant.partials.sms-clearing-workspace-shortcuts')
                @include('filament.tenant.partials.sms-clearing-queue-filters', [
                    'queueFilter' => $queueFilter,
                ])
            @elseif ($smsTab === SmsClearingTabRegistry::TAB_HISTORY)
                @include('filament.tenant.partials.sms-clearing-history-combined')
            @endif
        </div>
    </div>
</section>

{{-- History hides EmbeddedTable; HasTable pages omit page action modals otherwise. --}}
@include('filament.tenant.partials.page-workspace-action-modals')
