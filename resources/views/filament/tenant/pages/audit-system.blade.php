<x-filament-panels::page>
    <div class="lg:grid lg:grid-cols-[minmax(0,14rem)_1fr] lg:gap-8">
        <aside class="mb-6 space-y-1 lg:mb-0">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('System workspace') }}
            </p>
            @foreach ($this->getSideTabOptions() as $key => $item)
                <button type="button" wire:click="$set('sideTab', '{{ $key }}')" @class([
                    'flex w-full items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm font-medium transition',
                    'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800/40 dark:bg-sky-950/30 dark:text-sky-200' => $this->sideTab === $key,
                    'border-transparent text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $this->sideTab !== $key,
                ])>
                    <x-dynamic-component :component="$item['icon']" class="h-5 w-5 shrink-0" />
                    {{ $item['label'] }}
                </button>
            @endforeach

            @if ($this->tenantUserIsAdmin())
                <a href="{{ \App\Filament\Tenant\Pages\MessagesInboxPage::getUrl() }}"
                    class="flex w-full items-center gap-2 rounded-lg border border-transparent px-3 py-2 text-left text-sm font-medium text-gray-700 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">
                    <x-heroicon-o-chat-bubble-left-right class="h-5 w-5 shrink-0" />
                    {{ __('Messages inbox') }}
                </a>
            @endif
        </aside>

        <div class="min-w-0 space-y-6" wire:key="audit-system-{{ $this->sideTab }}">
            @if ($this->sideTab === 'audit')
                <div
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
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
                    {{ $this->table }}
                </div>
            @elseif ($this->sideTab === 'notifications')
                <div
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                    <div class="mb-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ __('Notification delivery log') }}</h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Email, SMS, WhatsApp, and in-app delivery attempts.') }}
                        </p>
                    </div>
                    {{ $this->table }}
                </div>
            @elseif ($this->sideTab === 'jobs')
                <div
                    class="rounded-xl border border-sky-200 bg-white px-4 py-3 text-sm text-gray-700 shadow-sm dark:border-sky-800/40 dark:bg-slate-800 dark:text-gray-300">
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
                <div
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
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
    </div>
</x-filament-panels::page>