@if ($this->advancedUi)
    <div
        class="rounded-xl border border-sky-200 bg-sky-50/60 px-4 py-3 text-sm text-gray-700 shadow-sm dark:border-sky-800/40 dark:bg-sky-950/20 dark:text-gray-300">
        <p class="font-semibold text-gray-900 dark:text-white">{{ __('Server scheduler required') }}</p>
        <p class="mt-1 text-xs leading-relaxed">
            {{ __('Scheduled jobs run via Laravel’s scheduler. On the host, add a cron entry that runs every minute:') }}
            <code
                class="mt-1 block rounded border border-gray-200 bg-gray-50 px-2 py-1 font-mono text-[11px] text-gray-800 dark:border-white/10 dark:bg-slate-900 dark:text-gray-200">* * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1</code>
        </p>
    </div>
@endif