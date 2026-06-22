@php
    $pendingBank = $this->getPendingBankClearanceCount();
    $openCount = $this->getOpenExceptionCount();
@endphp

<div class="ff-recon-shortcuts mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
    <a href="{{ $this->getBankClearingUrl() }}"
        class="ff-recon-shortcut-card group rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-sky-300 hover:shadow-md dark:border-white/10 dark:bg-gray-900/60 dark:hover:border-sky-500/40">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">
                    {{ __('Bank clearing') }}</p>
                <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ __('Match pending bank lines') }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Clear deposits, cash-outs, and invest flows against imported statements.') }}</p>
            </div>
            @if ($pendingBank > 0)
                <span
                    class="inline-flex min-w-[1.5rem] justify-center rounded-full bg-amber-500 px-2 py-0.5 text-xs font-semibold text-white">{{ $pendingBank }}</span>
            @endif
        </div>
    </a>

    <button type="button" wire:click="setSideTab('exceptions')"
        class="ff-recon-shortcut-card group rounded-xl border border-gray-200 bg-white p-4 text-start shadow-sm transition hover:border-red-300 hover:shadow-md dark:border-white/10 dark:bg-gray-900/60 dark:hover:border-red-500/40">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-red-600 dark:text-red-400">
                    {{ __('Exception queue') }}</p>
                <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ __('Review open issues') }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Open a row for context, links, and grouped resolution actions.') }}</p>
            </div>
            @if ($openCount > 0)
                <span
                    class="inline-flex min-w-[1.5rem] justify-center rounded-full bg-red-500 px-2 py-0.5 text-xs font-semibold text-white">{{ $openCount }}</span>
            @endif
        </div>
    </button>

    <a href="{{ $this->getReconciliationSettingsUrl() }}"
        class="ff-recon-shortcut-card group rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-gray-300 hover:shadow-md dark:border-white/10 dark:bg-gray-900/60 dark:hover:border-white/20">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Settings') }}</p>
            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ __('Tolerances & bank defaults') }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Statement balance defaults and reconciliation thresholds.') }}</p>
        </div>
    </a>
</div>