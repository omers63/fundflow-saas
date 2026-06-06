<x-filament-panels::page>
    <div class="space-y-10">
        <div class="space-y-6">
            <div class="rounded-2xl bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm overflow-hidden">
                <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 dark:border-gray-700 bg-gradient-to-r from-emerald-50 to-teal-50 dark:from-emerald-900/20 dark:to-teal-900/20">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 dark:bg-emerald-900/50 ring-1 ring-emerald-200 dark:ring-emerald-800">
                        <x-heroicon-o-arrow-down-tray class="w-6 h-6 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Database backups') }}</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                            <strong class="text-gray-700 dark:text-gray-300">{{ __('Download backup') }}</strong> {{ __('streams a copy to your browser without saving on the server.') }}
                            <strong class="text-gray-700 dark:text-gray-300">{{ __('Save backup to server') }}</strong> {{ __('writes to your tenant backup folder and records it in the history table below.') }}
                        </p>
                    </div>
                </div>

                <div class="px-6 py-5 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                    <div class="rounded-xl bg-gray-50 dark:bg-gray-900/40 ring-1 ring-gray-100 dark:ring-gray-700 p-4 space-y-2">
                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ __('SQLite') }}</p>
                        <p>{{ __('Downloads the configured') }} <code class="text-xs bg-gray-200 dark:bg-gray-700 px-1.5 py-0.5 rounded">.sqlite</code> {{ __('file as-is. Fast and complete.') }}</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 dark:bg-gray-900/40 ring-1 ring-gray-100 dark:ring-gray-700 p-4 space-y-2">
                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ __('MySQL / MariaDB') }}</p>
                        <p>{{ __('Runs') }} <code class="text-xs bg-gray-200 dark:bg-gray-700 px-1.5 py-0.5 rounded">mysqldump</code> {{ __('using your connection settings. The client tools must be installed and available in your system PATH.') }}</p>
                    </div>
                    <p class="text-xs text-amber-600 dark:text-amber-400 flex items-start gap-2">
                        <x-heroicon-o-shield-exclamation class="w-4 h-4 flex-shrink-0 mt-0.5" />
                        <span>{{ __('Store backups securely. Anyone with an admin session can use this download while logged in.') }}</span>
                    </p>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl bg-gradient-to-br from-red-600 to-rose-700 shadow-lg ring-1 ring-red-800 overflow-hidden">
                <div class="px-6 py-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="flex items-start gap-4">
                        <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-white/15 ring-2 ring-white/20">
                            <x-heroicon-o-exclamation-triangle class="w-7 h-7 text-white" />
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-white">{{ __('Purge database (destructive)') }}</h2>
                            <p class="mt-1 text-sm text-red-100">
                                {{ __('Purge removes') }} <strong class="text-white">{{ __('all rows') }}</strong> {{ __('from every table that does') }} <strong class="text-white">{{ __('not') }}</strong> {{ __('have a') }}
                                <code class="text-xs bg-white/20 px-1 rounded">deleted_at</code> {{ __('column, except protected system tables (users, permissions, migrations, queues, cache, sessions).') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/80">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-table-cells class="w-5 h-5 text-red-500" />
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Tables that will be emptied') }}</h3>
                    </div>
                    <span class="text-xs font-medium text-gray-500">{{ trans_choice(':count table|:count tables', count($purgeableTables), ['count' => count($purgeableTables)]) }}</span>
                </div>
                <div class="px-6 py-4 max-h-64 overflow-y-auto">
                    @if(count($purgeableTables) === 0)
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No tables match the purge rules.') }}</p>
                    @else
                        <ul class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                            @foreach($purgeableTables as $table)
                                <li class="flex items-center gap-2 rounded-lg bg-red-50 dark:bg-red-900/15 ring-1 ring-red-100 dark:ring-red-900/40 px-3 py-2 text-sm font-mono text-red-800 dark:text-red-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-red-500 flex-shrink-0"></span>
                                    {{ $table }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="rounded-2xl bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                            <x-heroicon-o-shield-check class="w-4 h-4 text-emerald-500" />
                            {{ __('Always preserved') }}
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Never truncated by this tool.') }}</p>
                    </div>
                    <div class="px-6 py-4 max-h-48 overflow-y-auto">
                        <ul class="space-y-1">
                            @foreach($alwaysExcludedTables as $table)
                                <li class="text-xs font-mono text-gray-600 dark:text-gray-400">{{ $table }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="rounded-2xl bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                            <x-heroicon-o-archive-box-x-mark class="w-4 h-4 text-amber-500" />
                            {{ __('Skipped (has deleted_at)') }}
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Not included in purge while this column exists.') }}</p>
                    </div>
                    <div class="px-6 py-4 max-h-48 overflow-y-auto">
                        @if(count($softDeleteSkippedTables) === 0)
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('None of your application tables currently use soft deletes.') }}</p>
                        @else
                            <ul class="space-y-1">
                                @foreach($softDeleteSkippedTables as $table)
                                    <li class="text-xs font-mono text-amber-700 dark:text-amber-300">{{ $table }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
