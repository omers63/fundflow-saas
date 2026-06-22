<div class="ff-bank-clearing-shortcuts mb-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
    <a href="{{ $this->getMasterCashUrl() }}"
        class="ff-bank-clearing-shortcut-card group rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-sky-300 hover:shadow-md dark:border-white/10 dark:bg-gray-900/60 dark:hover:border-sky-500/40">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">
                {{ __('Master cash') }}
            </p>
            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ __('Review cash ledger') }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Open the master cash account for manual adjustments and pending bank lines.') }}
            </p>
        </div>
    </a>

    <a href="{{ $this->getReconciliationUrl() }}"
        class="ff-bank-clearing-shortcut-card group rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-red-300 hover:shadow-md dark:border-white/10 dark:bg-gray-900/60 dark:hover:border-red-500/40">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-red-600 dark:text-red-400">
                {{ __('Reconciliation') }}
            </p>
            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ __('Exception queue') }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Review ledger drift, stale pending lines, and bank match exceptions.') }}
            </p>
        </div>
    </a>

    <a href="{{ $this->getBankTemplatesSettingsUrl() }}"
        class="ff-bank-clearing-shortcut-card group rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-gray-300 hover:shadow-md dark:border-white/10 dark:bg-gray-900/60 dark:hover:border-white/20">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Settings') }}
            </p>
            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ __('Bank templates') }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Manage CSV column mappings and parsing templates.') }}
            </p>
        </div>
    </a>
</div>