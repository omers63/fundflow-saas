<x-filament-panels::page>
    <section
        class="rounded-2xl border border-gray-200 bg-white px-5 py-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <header class="mb-4 border-b border-gray-100 pb-4 dark:border-white/10">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Audit & System') }}</h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ $this->getSubheading() }}
                    </p>
                </div>
                @if ($this->sideTab === 'jobs' && $this->advancedUiAvailable())
                    @include('filament.tenant.partials.advanced-ui-toggle')
                @endif
            </div>
        </header>

        @include('filament.tenant.partials.audit-system-tab-pills')

        <div class="min-w-0 space-y-6" wire:key="audit-system-{{ $this->sideTab }}-{{ $this->jobsTab }}">
            @if ($this->sideTab === 'audit')
                <div>
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Fund audit log') }}</h3>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Immutable event trail for ledger, reconciliation, and admin actions.') }}
                            </p>
                        </div>
                        <div class="ff-tenant-tab-pills flex flex-wrap gap-2">
                            @foreach ($this->getAuditFilterOptions() as $filterKey => $filterLabel)
                                <button type="button" wire:click="setAuditFilter('{{ $filterKey }}')" @class([
            'ff-tenant-tab-pills__item',
            'ff-tenant-tab-pills__item--active' => $this->auditFilter === $filterKey,
        ])>
                                    <x-ff-tab-pill-label :label="$filterLabel" :key="$filterKey" />
                                </button>
                            @endforeach
                        </div>
                    </div>
                    @include('filament.tenant.partials.audit-system-logging-controls', [
        'loggingTitle' => __('Audit logging'),
        'loggingDescription' => __('Turn off to stop writing new rows to the fund audit log table. Existing rows stay until you empty the table.'),
        'toggleProperty' => 'auditLoggingEnabled',
        'toggleLabel' => __('Record new audit log entries'),
        'rowCount' => $this->fundAuditLogRowCount(),
        'truncateAction' => 'truncateFundAuditLogs',
        'truncateLabel' => __('Empty audit log table'),
        'truncateConfirm' => __('Permanently delete every row in the fund audit log table? This cannot be undone.'),
    ])
                    <div wire:key="audit-system-table-audit-{{ $this->auditFilter }}">
                        {{ $this->table }}
                    </div>
                </div>
            @elseif ($this->sideTab === 'notifications')
                <div>
                    <div class="mb-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ __('Notification delivery log') }}</h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Email, SMS, WhatsApp, and in-app delivery attempts.') }}
                        </p>
                    </div>
                    @include('filament.tenant.partials.audit-system-logging-controls', [
        'loggingTitle' => __('Notification logging'),
        'loggingDescription' => __('Turn off to stop writing new rows to the notification log table. Existing rows stay until you empty the table.'),
        'toggleProperty' => 'notificationLoggingEnabled',
        'toggleLabel' => __('Record notification delivery log entries'),
        'rowCount' => $this->notificationLogRowCount(),
        'truncateAction' => 'truncateNotificationLogs',
        'truncateLabel' => __('Empty notification log table'),
        'truncateConfirm' => __('Permanently delete every row in the notification log table? This cannot be undone.'),
    ])
                    <div wire:key="audit-system-table-notifications">
                        {{ $this->table }}
                    </div>
                </div>
            @elseif ($this->sideTab === 'jobs')
                                        @if ($this->batchPostingIsHalted())
                                            <div role="alert"
                                                class="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900 shadow-sm dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200">
                                                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0" />
                                                <div class="min-w-0 text-sm">
                                                    <p class="font-semibold">{{ __('Batch posting halted') }}</p>
                                                    <p class="mt-0.5 text-xs">{{ $this->batchPostingHaltReason() ?? __('Critical reconciliation issue') }}</p>
                                                </div>
                                            </div>
                                        @endif

                                        <div class="mb-4">
                                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Automation') }}</h3>
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                {{ __('Scheduled fund operations grouped by area.') }}
                                            </p>
                                        </div>

                                        @include('filament.tenant.partials.jobs.scheduler-notice')

                                        <div class="ff-tenant-tab-pills flex flex-wrap gap-2">
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
                                            <div wire:key="audit-system-table-jobs-{{ $this->jobsTab }}-{{ (int) $this->advancedUi }}">
                                                {{ $this->table }}
                                            </div>
                                        @endif
            @elseif ($this->sideTab === 'maintenance')
                @livewire(\App\Filament\Tenant\Pages\SystemMaintenancePage::class, ['embedded' => true], key('audit-system-maintenance-' . (int) $this->advancedUi))
            @elseif ($this->sideTab === 'migration')
                @livewire(\App\Filament\Tenant\Pages\LegacyMigrationPage::class, ['embedded' => true], key('audit-system-migration'))
            @else
                @livewire(\App\Filament\Tenant\Pages\FiscalYearClosePage::class, ['embedded' => true], key('audit-system-fiscal'))
            @endif
        </div>
    </section>
</x-filament-panels::page>
