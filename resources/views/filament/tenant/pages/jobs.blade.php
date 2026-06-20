<x-filament-panels::page>
    <div
        class="mb-4 overflow-hidden rounded-xl border border-sky-200 bg-white px-4 py-3 text-sm text-gray-700 shadow-sm dark:border-sky-800/40 dark:bg-slate-800 dark:text-gray-300">
        <p class="font-semibold text-gray-900 dark:text-white">{{ __('Server scheduler required') }}</p>
        <p class="mt-1 text-xs leading-relaxed">
            {{ __('Scheduled jobs run via Laravel’s scheduler. On the host, add a cron entry that runs every minute:') }}
            <code
                class="mt-1 block rounded border border-gray-200 bg-gray-50 px-2 py-1 font-mono text-[11px] text-gray-800 dark:border-white/10 dark:bg-slate-900 dark:text-gray-200">* * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1</code>
        </p>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Use Run now below for manual runs. Tenant schedules are defined in routes/console.php.') }}
        </p>
    </div>

    <div class="ff-tenant-tab-pills mb-4 flex flex-wrap gap-2">
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

    <div wire:key="jobs-table-{{ $jobsTab }}">
        {{ $this->table }}
    </div>
</x-filament-panels::page>