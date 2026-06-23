<x-filament-panels::page>
    <section
        class="rounded-2xl border border-gray-200 bg-white px-5 py-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <header class="mb-4 border-b border-gray-100 pb-4 dark:border-white/10">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Audit & System') }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $this->getSubheading() }}
            </p>
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
                                    {{ $filterLabel }}
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
                <div
                    class="rounded-xl border border-sky-200 bg-sky-50/60 px-4 py-3 text-sm text-gray-700 shadow-sm dark:border-sky-800/40 dark:bg-sky-950/20 dark:text-gray-300">
                    <p class="font-semibold text-gray-900 dark:text-white">{{ __('Server scheduler required') }}</p>
                    <p class="mt-1 text-xs">
                        {{ __('Scheduled jobs run via Laravel’s scheduler on the host (cron every minute).') }}</p>
                </div>
                <div class="ff-tenant-tab-pills flex flex-wrap gap-2">
                    <button type="button" wire:click="setJobsTab('catalog')" @class([
                        'ff-tenant-tab-pills__item',
                        'ff-tenant-tab-pills__item--active' => $jobsTab === 'catalog',
                    ])>
                        {{ __('Job catalog') }}
                    </button>
                    <button type="button" wire:click="setJobsTab('history')" @class([
                        'ff-tenant-tab-pills__item',
                        'ff-tenant-tab-pills__item--active' => $jobsTab === 'history',
                    ])>
                        {{ __('Run history') }}
                    </button>
                </div>
                <div wire:key="audit-system-table-jobs-{{ $this->jobsTab }}">
                    {{ $this->table }}
                </div>
            @elseif ($this->sideTab === 'maintenance')
                @livewire(\App\Filament\Tenant\Pages\SystemMaintenancePage::class, ['embedded' => true], key('audit-system-maintenance'))
            @elseif ($this->sideTab === 'migration')
                @livewire(\App\Filament\Tenant\Pages\LegacyMigrationPage::class, ['embedded' => true], key('audit-system-migration'))
            @else
                @livewire(\App\Filament\Tenant\Pages\FiscalYearClosePage::class, ['embedded' => true], key('audit-system-fiscal'))
            @endif
        </div>
    </section>
</x-filament-panels::page>
