    <div class="ff-system-maintenance mx-auto w-full space-y-8">
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
                        <strong
                            class="font-medium text-gray-700 dark:text-gray-300">{{ __('Download backup') }}</strong>
                        {{ __('streams a copy to your browser without saving on the server.') }}
                        <strong
                            class="font-medium text-gray-700 dark:text-gray-300">{{ __('Save backup to server') }}</strong>
                        {{ __('writes to your tenant backup folder and records it in the history table below.') }}
                    </p>
                </div>
            </header>

            <div class="ff-maintenance-panel__body space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="ff-maintenance-callout">
                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ __('SQLite') }}</p>
                        <p class="mt-1">
                            {{ __('Downloads the configured') }}
                            <code class="rounded bg-gray-200 px-1.5 py-0.5 text-xs dark:bg-gray-700">.sqlite</code>
                            {{ __('file as-is. Fast and complete.') }}
                        </p>
                    </div>
                    <div class="ff-maintenance-callout">
                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ __('MySQL / MariaDB') }}</p>
                        <p class="mt-1">
                            {{ __('Runs') }}
                            <code class="rounded bg-gray-200 px-1.5 py-0.5 text-xs dark:bg-gray-700">mysqldump</code>
                            {{ __('using your connection settings. The client tools must be installed and available in your system PATH.') }}
                        </p>
                    </div>
                </div>
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
                        {{ __('Purge removes') }}
                        <strong>{{ __('all rows') }}</strong>
                        {{ __('from every table that does') }}
                        <strong>{{ __('not') }}</strong>
                        {{ __('have a') }}
                        <code class="rounded bg-red-50 px-1 text-xs dark:bg-red-950/30">deleted_at</code>
                        {{ __('column, except protected system tables (users, permissions, migrations, queues, cache, sessions).') }}
                    </p>
                </div>
            </div>

            <div class="ff-maintenance-panel">
                <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                    <div class="flex min-w-0 items-center gap-2">
                        <x-heroicon-o-table-cells class="h-5 w-5 shrink-0 text-red-500" />
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                            {{ __('Tables that will be emptied') }}
                        </h3>
                    </div>
                    <span class="shrink-0 text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ trans_choice(':count table|:count tables', count($purgeableTables), ['count' => count($purgeableTables)]) }}
                    </span>
                </header>
                <div class="ff-maintenance-panel__body">
                    <div class="ff-maintenance-scroll max-h-64">
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
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="ff-maintenance-panel">
                    <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
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
                    <div class="ff-maintenance-panel__body">
                        <div class="ff-maintenance-scroll max-h-48">
                            <ul class="space-y-1">
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
                    </header>
                    <div class="ff-maintenance-panel__body">
                        <div class="ff-maintenance-scroll max-h-48">
                            @if (count($softDeleteSkippedTables) === 0)
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('None of your application tables currently use soft deletes.') }}
                                </p>
                            @else
                                <ul class="space-y-1">
                                    @foreach ($softDeleteSkippedTables as $table)
                                        <li class="font-mono text-xs text-amber-700 dark:text-amber-300">{{ $table }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <p class="text-center text-xs text-gray-400 dark:text-gray-500">
                {{ __('After purging business data you may need to run') }}
                <code class="rounded bg-gray-200 px-1 dark:bg-gray-700">php artisan db:seed</code>
                {{ __('to restore defaults.') }}
            </p>
        </section>
    </div>
