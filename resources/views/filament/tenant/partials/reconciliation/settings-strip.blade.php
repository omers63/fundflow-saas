@php
    $settings = $this->getReconciliationSettingsSummary();
@endphp

<div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Current reconciliation settings') }}</h3>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Used by Run checks on this page and by the scheduled daily / nightly / monthly jobs.') }}
            </p>
        </div>
        <a href="{{ $this->getReconciliationSettingsUrl() }}"
            class="ff-tenant-btn inline-flex shrink-0 items-center gap-1.5 border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-800 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:text-white dark:hover:bg-white/5">
            {{ __('Edit in Settings') }}
        </a>
    </div>

    <dl class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Declared bank balance') }}</dt>
            <dd class="mt-0.5 text-sm font-semibold text-gray-900 dark:text-white">
                {{ $settings['bank_balance_label'] }}
            </dd>
            <dd class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                {{ $settings['bank_date_label'] }}
            </dd>
        </div>
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Bank vs book variance') }}</dt>
            <dd class="mt-0.5 text-sm font-semibold text-gray-900 dark:text-white">
                {{ $settings['bank_critical_label'] }}
            </dd>
        </div>
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Amount tolerance') }}</dt>
            <dd class="mt-0.5 text-sm font-semibold text-gray-900 dark:text-white">
                {{ $settings['tolerance_label'] }}
            </dd>
            <dd class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Matching & auto-resolve') }}
            </dd>
        </div>
        <div>
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Monthly snapshot day') }}</dt>
            <dd class="mt-0.5 text-sm font-semibold text-gray-900 dark:text-white">
                {{ $settings['month_boundary_label'] }}
            </dd>
            <dd class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                {{ __('At 00:30 with statements') }}
            </dd>
        </div>
    </dl>
</div>
