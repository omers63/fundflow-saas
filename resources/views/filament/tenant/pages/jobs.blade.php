<x-filament-panels::page>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $this->getSubheading() }}</p>
        </div>
        @if ($this->advancedUiAvailable())
            @include('filament.tenant.partials.advanced-ui-toggle')
        @endif
    </div>
    
    @if ($this->batchPostingIsHalted())
        <div role="alert"
            class="mb-4 flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900 shadow-sm dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200">
            <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0" />
            <div class="min-w-0 text-sm">
                <p class="font-semibold">{{ __('Batch posting halted') }}</p>
                <p class="mt-0.5 text-xs">{{ $this->batchPostingHaltReason() ?? __('Critical reconciliation issue') }}</p>
            </div>
        </div>
    @endif
    
    @include('filament.tenant.partials.jobs.scheduler-notice')
    
    <div class="ff-tenant-tab-pills mb-4 flex flex-wrap gap-2">
        <button type="button" wire:click="setJobsTab('status')" @class([
    'ff-tenant-tab-pills__item',
    'ff-tenant-tab-pills__item--active' => $jobsTab === 'status',
])>
            <x-ff-tab-pill-label :label="__('Status')" key="status" />
        </button>
        @if ($this->advancedUi)
                    <button type="button" wire:click="setJobsTab('catalog')" @class([
                'ff-tenant-tab-pills__item',
                'ff-tenant-tab-pills__item--active' => $jobsTab === 'catalog',
            ])>
                        <x-ff-tab-pill-label :label="__('Job catalog')" key="catalog" />
                        </button>
                        <button type="button" wire:click="setJobsTab('history')" @class([
                            'ff-tenant-tab-pills__item',
                            'ff-tenant-tab-pills__item--active' => $jobsTab === 'history',
                        ])>
                        <x-ff-tab-pill-label :label="__('Run history')" key="history" />
                    </button>
        @endif
    </div>
    
    @if ($jobsTab === 'status')
        @include('filament.tenant.partials.jobs.automation-status')
    @else
        <div wire:key="jobs-table-{{ $jobsTab }}-{{ (int) $this->advancedUi }}">
            {{ $this->table }}
        </div>
    @endif
    </x-filament-panels::page>