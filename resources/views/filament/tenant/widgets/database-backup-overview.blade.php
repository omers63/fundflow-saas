@php
    $s = $stats;
@endphp
<div class="ff-maintenance-panel fi-wi-database-backup-overview">
    <header class="ff-maintenance-panel__header ff-maintenance-panel__header--blue">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/50">
            <x-heroicon-o-circle-stack class="h-5 w-5 text-blue-600 dark:text-blue-400" />
        </div>
        <div class="min-w-0">
            <h2 id="ff-maintenance-overview-heading" class="text-base font-semibold text-gray-900 dark:text-white">
                {{ __('Database overview') }}
            </h2>
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Live connection stats and backup storage summary.') }}
            </p>
        </div>
    </header>

    <div class="ff-maintenance-panel__body">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="ff-maintenance-stat">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Driver') }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $s['driver'] }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Connection: :name', ['name' => $s['connection']]) }}</p>
            </div>
            <div class="ff-maintenance-stat">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Database') }}</p>
                <p class="mt-1 break-all text-lg font-semibold text-gray-900 dark:text-white">{{ $s['display_name'] }}
                </p>
                @if ($s['path_or_schema'] && $s['driver'] === 'sqlite')
                    <p class="mt-1 break-all font-mono text-xs text-gray-500 dark:text-gray-400">{{ $s['path_or_schema'] }}
                    </p>
                @endif
            </div>
            <div class="ff-maintenance-stat">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Reported size') }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $liveSize }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if ($modified)
                        {{ __('File last modified: :date', ['date' => $modified]) }}
                    @elseif (in_array($s['driver'], ['mysql', 'mariadb'], true))
                        {{ __('Approx. data + index (information_schema)') }}
                    @else
                        —
                    @endif
                </p>
            </div>
            <div class="ff-maintenance-stat">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Tables') }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">
                    {{ number_format($s['table_count']) }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('On default connection') }}</p>
            </div>
            <div class="ff-maintenance-stat">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Stored backups') }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">
                    {{ number_format($s['stored_backup_count']) }}
                    <span class="text-sm font-normal text-gray-500">{{ __('files') }}</span>
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Recorded total: :size', ['size' => $storedTotal]) }}</p>
            </div>
            <div class="ff-maintenance-stat">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Backup folder') }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $folderTotal }}</p>
                <p class="mt-1 font-mono text-xs text-gray-500 dark:text-gray-400">storage/app/{{ $backupFolder }}/</p>
            </div>
        </div>
    </div>
</div>