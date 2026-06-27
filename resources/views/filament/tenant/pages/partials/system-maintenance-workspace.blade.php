    <div class="ff-system-maintenance mx-auto w-full space-y-8">
        <section class="ff-maintenance-panel" aria-labelledby="ff-member-portal-maintenance-heading">
            <header class="ff-maintenance-panel__header ff-maintenance-panel__header--amber">
                <div class="ff-maintenance-panel__header-icon">
                    <x-heroicon-o-wrench-screwdriver class="h-5 w-5 text-amber-600 dark:text-amber-400" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 id="ff-member-portal-maintenance-heading"
                            class="text-base font-semibold text-gray-900 dark:text-white">
                            {{ __('Member portal maintenance') }}
                        </h2>
                        <span @class([
    'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200' => $memberPortalMaintenanceEnabled,
    'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200' => !$memberPortalMaintenanceEnabled,
])>
                            {{ $memberPortalMaintenanceEnabled ? __('Maintenance') : __('Online') }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Members cannot sign in and active sessions are ended. Tenant admin access is not affected. Admin impersonation still works.') }}
                    </p>
                </div>
            </header>

            <div class="ff-maintenance-panel__body space-y-4">
                <label class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" wire:model.live="memberPortalMaintenanceEnabled"
                        class="rounded border-gray-300 text-amber-600 focus:ring-amber-500 dark:border-white/20 dark:bg-gray-900">
                    <span>{{ __('Put member portal in maintenance mode') }}</span>
                </label>

                @if ($memberPortalMaintenanceEnabled)
                    <div>
                        <label for="member-portal-maintenance-message"
                            class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ __('Message shown to members') }}
                        </label>
                        <textarea id="member-portal-maintenance-message" wire:model="memberPortalMaintenanceMessage"
                            rows="3"
                            class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100"
                            placeholder="{{ __('System under maintenance. Member portal sign-in is temporarily unavailable. Please try again later.') }}"></textarea>
                        <div class="mt-2 flex justify-end">
                            <button type="button" wire:click="saveMemberPortalMaintenanceMessage"
                                class="ff-tenant-btn ff-tenant-btn--secondary px-3 py-1.5 text-xs">
                                {{ __('Save message') }}
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </section>

        {{-- Database overview --}}
        <section aria-labelledby="ff-maintenance-overview-heading">
            @livewire(\App\Filament\Tenant\Widgets\DatabaseBackupOverviewWidget::class, key('system-maintenance-backup-overview'))
        </section>

        {{-- Backup guide --}}
        <section class="ff-maintenance-panel" aria-labelledby="ff-maintenance-backups-heading">
            <header class="ff-maintenance-panel__header ff-maintenance-panel__header--emerald">
                <div class="ff-maintenance-panel__header-icon">
                    <x-heroicon-o-arrow-down-tray class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                </div>
                <div class="min-w-0">
                    <h2 id="ff-maintenance-backups-heading"
                        class="text-base font-semibold text-gray-900 dark:text-white">
                        {{ __('Database backups') }}
                    </h2>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Download a copy to your browser, or save a backup file on the server and track it in the history table below.') }}
                    </p>
                </div>
            </header>

            <div class="ff-maintenance-panel__body space-y-4 text-sm text-gray-600 dark:text-gray-300">
                @include('filament.tenant.partials.audit-system.workspace-actions', [
    'names' => ['saveToServer', 'download'],
    'class' => 'pb-1',
])

                @if ($this->advancedUi)
                    <details class="ff-maintenance-callout">
                        <summary class="cursor-pointer font-medium text-gray-800 dark:text-gray-200">
                            {{ __('Database engine notes') }}
                        </summary>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="font-medium text-gray-800 dark:text-gray-200">{{ __('SQLite') }}</p>
                                <p class="mt-1">
                                    {{ __('Downloads the configured') }}
                                    <code class="rounded bg-gray-200 px-1.5 py-0.5 text-xs dark:bg-gray-700">.sqlite</code>
                                    {{ __('file as-is.') }}
                                </p>
                            </div>
                            <div>
                                <p class="font-medium text-gray-800 dark:text-gray-200">{{ __('MySQL / MariaDB') }}</p>
                                <p class="mt-1">
                                        {{ __('Runs') }}
                                            <code class="rounded bg-gray-200 px-1.5 py-0.5 text-xs dark:bg-gray-700">mysqldump</code>
                                                {{ __('using your connection settings.') }}
                                                </p>
                                                </div>
                                                            </div>
                                        </details>
                @endif

                                                        <p class="flex items-start gap-2 text-xs text-amber-600 dark:text-amber-400">
                                            <x-heroicon-o-shield-exclamation class="mt-0.5 h-4 w-4 shrink-0" />
                                                <span>{{ __('Store backups securely. Anyone with an admin session can use this download while logged in.') }}</span>
                                                    </p>
                                        </div>
                                    </section>

                                                                {{-- Backup history --}}
                                            <section aria-labelledby="ff-maintenance-history-heading">
                                        @livewire(\App\Filament\Tenant\Widgets\DatabaseBackupsTableWidget::class, key('system-maintenance-backups-table'))
                                </section>
                        
                                @if ($this->advancedUi)
                                                            {{-- Purge database --}}
                                                            <section class="space-y-6" aria-labelledby="ff-maintenance-purge-heading">
                                                                <div class="ff-maintenance-danger-banner">
                                                                    <div class="ff-maintenance-danger-banner__icon">
                                                                        <x-heroicon-o-exclamation-triangle class="h-6 w-6" />
                                                                    </div>
                                                                    <div class="min-w-0">
                                                                        <h2 id="ff-maintenance-purge-heading" class="ff-maintenance-danger-banner__title">
                                                                            {{ __('Purge database (destructive)') }}
                                                                        </h2>
                                                                        <p class="ff-maintenance-danger-banner__body">
                                                                            {{ __('Purge removes all rows from every table that does not have a deleted_at column, except protected system tables (users, permissions, migrations, queues, cache, sessions).') }}
                                                                                    </p>
                                                                    </div>
                                                    </di            v>

                                                                <div class="           ff-maintenance-panel">
                                                         <header            class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                                                             <div cla           ss="flex min-w-0 items-center gap-2">
                                                                            <x-heroicon-o-table-cells class="h-5 w-5 shrink-0 text-red-500" />
                                                                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                                                                {{ __('Tables that will be emptied') }}
                                                                            </h3>
                                                                        </div>
                                                                        <span class="shrink-0 text-xs font-medium text-gray-500 dark:text-gray-400">
                                                                            {{ trans_choice(':count table|:count tables', count($purgeableTables), ['count' => count($purgeableTables)]) }}
                                                                        </span>
                                                           </header         >
                                                           <div class="     ff-maintenance-panel__body">
                                                                   <div cla ss="ff-maintenance-scroll max-h-64">
                                                                            @if (count($purgeableTables) === 0)
                                                                                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No tables match the purge rules.') }}
                                                                                </p>                
                                                                            @else
                                                                                    <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                                @foreach ($purgeableTables as $table)
                                                                    <li
                                                                                        class="flex items-center gap-2 rounded-lg border border-red-100 bg-red-50 px-3 py-2 font-mono text-sm text-red-800 dark:border-red-900/40 dark:bg-red-900/15 dark:text-red-200">
                                                                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-red-500"></span>
                                                                                    {{ $table }}
                                                                                    </li>
                                                                @endforeach
                                                                                    </ul>
                                                            @endif
                                                                                    </div>
                                                                    </div>
                                                              </di  v>

                                                                <div class="  grid grid-cols-1 gap-6 lg:grid-cols-2">
                                                                   <div class=" ff-maintenance-panel">
                                                                       <header clas s="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                                                                           <div>
                                                                                 <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                                                                                    <x-heroicon-o-shield-check class="h-4 w-4 text-emerald-500" />
                                                                                    {{ __('Always preserved') }}
                                                                                </h3>
                                                                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                                                    {{ __('Never truncated by this tool.') }}
                                                                                </p>
                                                                            </div>
                                                                        </header>
                                                <div class="                        ff-maintenance-panel__body">
                                                    <div class="                    ff-maintenance-scroll max-h-48">
                                                        <ul                         class="space-y-1">
                                                                                    @foreach ($alwaysExcludedTables as $table)
                                                                                        <li class="font-mono text-xs text-gray-600 dark:text-gray-400">{{ $table }}</li>
                                                                                    @endforeach
                                                                                </ul>
                                                                                        </div>
                                                                        </div>
                                                                    </div>

                                                                    <div class="ff-maintenance-panel">
                                                                        <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                                                                            <div>
                                                                                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                                                                                    <x-heroicon-o-archive-box-x-mark class="h-4 w-4 text-amber-500" />
                                                                                    {{ __('Skipped (has deleted_at)') }}
                                                                                </h3>
                                                                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                                                    {{ __('Not included in purge while this column exists.') }}
                                                                                </p>
                                                                            </div>
                                                                     </header   >
                                                         <div class="           ff-maintenance-panel__body">
                                                     <div class="                   ff-maintenance-scroll max-h-48">
                                                              @if (count($softDeleteSkippedTables) === 0)
                                                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                                            {{ __('None of your application tables currently use soft deletes.') }}
                                                                  </p>      
                                                              @else
                                                                <ul                   class="space-y-1">
                                                                            @foreach ($softDeleteSkippedTables as $table)
                                                                                <li class="font-mono text-xs text-amber-700 dark:text-amber-300">{{ $table }}</li>
                                                                            @endforeach
                                                                            </ul>
                                                            @endif
                                                                            </div>
                                                                                    </div>
                                                                    </div>
                                                                </div>

                                        <p c                        lass="text-center text-xs text-gray-400 dark:text-gray-500">
                                                                    {{ __('After purging business data you may need to run') }}
                                                                                <code class="rounded bg-gray-200 px-1 dark:bg-gray-700">php artisan db:seed</code>
                                                                    {{ __('to restore defaults.') }}
                                                                </p>

                                                                                       @include('filament.tenant.partials.audit-system.workspace-actions', [
                                                                                        'names' => ['purge'],
                                                                                        'class' => 'flex justify-center pt-2',
                                                                                    ])
                                    </section>
                                @endif
    </div>
