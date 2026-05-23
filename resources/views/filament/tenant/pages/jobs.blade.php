<x-filament-panels::page>
    <div
        class="mb-4 rounded-xl border border-sky-200/80 bg-gradient-to-r from-sky-50/90 to-indigo-50/60 px-4 py-3 text-sm text-gray-700 shadow-sm dark:border-sky-500/30 dark:from-sky-950/40 dark:to-indigo-950/30 dark:text-gray-300">
        <p class="font-semibold text-gray-900 dark:text-white">{{ __('Server scheduler required') }}</p>
        <p class="mt-1 text-xs leading-relaxed">
            {{ __('Scheduled jobs run via Laravel’s scheduler. On the host, add a cron entry that runs every minute:') }}
            <code
                class="mt-1 block rounded bg-white/80 px-2 py-1 font-mono text-[11px] text-sky-900 dark:bg-gray-900/80 dark:text-sky-200">* * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1</code>
        </p>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Use Run now below for manual runs. Tenant schedules are defined in routes/console.php.') }}
        </p>
    </div>

    <div class="mb-4 flex flex-wrap gap-2">
        <x-filament::button :color="$jobsTab === 'catalog' ? 'primary' : 'gray'" wire:click="setJobsTab('catalog')"
            size="sm">
            {{ __('Job catalog') }}
        </x-filament::button>
        <x-filament::button :color="$jobsTab === 'history' ? 'primary' : 'gray'" wire:click="setJobsTab('history')"
            size="sm">
            {{ __('Run history') }}
        </x-filament::button>
    </div>

    <div wire:key="jobs-table-{{ $jobsTab }}">
        {{ $this->table }}
    </div>
</x-filament-panels::page>