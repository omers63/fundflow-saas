@php
    use App\Filament\Tenant\Support\SmsClearingTabRegistry;

    $smsTab = $smsTab ?? SmsClearingTabRegistry::TAB_QUEUE;
    $queueFilter = $queueFilter ?? SmsClearingTabRegistry::FILTER_ALL;
@endphp

<section
    class="ff-sms-clearing-shell rounded-2xl border border-gray-200 bg-white px-5 py-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
    <header class="mb-4 border-b border-gray-100 pb-4 dark:border-white/10">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('SMS clearing workspace') }}</h2>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ $this->getSubheading() }}
        </p>
    </header>

    @include('filament.tenant.partials.audit-system.workspace-actions', [
        'class' => 'ff-audit-workspace-actions ff-sms-clearing-workspace-actions mb-4',
    ])

    @include('filament.tenant.partials.sms-clearing-tab-pills', [
        'smsTab' => $smsTab,
    ])

    <div class="min-w-0 space-y-4" wire:key="sms-clearing-workspace-{{ $smsTab }}-{{ $queueFilter }}">
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
</section>
