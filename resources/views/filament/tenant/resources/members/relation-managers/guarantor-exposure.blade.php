@php
    $summary = $this->exposureSummary();
    $currency = \App\Models\Tenant\Setting::get('general', 'currency', 'USD');
@endphp

<div class="space-y-4">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Total guaranteed') }}</p>
            <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">
                {{ \App\Filament\Support\MoneyDisplay::format($summary['total_exposure'], $currency) }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Max single exposure') }}</p>
            <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">
                {{ \App\Filament\Support\MoneyDisplay::format($summary['max_single_exposure'], $currency) }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Guaranteed loans') }}</p>
            <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $summary['loan_count'] }}</p>
        </div>
        <div @class([
            'rounded-xl border px-4 py-3 shadow-sm',
            'border-rose-200 bg-rose-50 dark:border-rose-800/40 dark:bg-rose-950/30' => $summary['has_risk'],
            'border-emerald-200 bg-emerald-50 dark:border-emerald-800/40 dark:bg-emerald-950/30' => !$summary['has_risk'],
        ])>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Risk flag') }}</p>
            <p @class([
                'mt-1 text-sm font-semibold',
                'text-rose-800 dark:text-rose-200' => $summary['has_risk'],
                'text-emerald-800 dark:text-emerald-200' => !$summary['has_risk'],
            ])>
                {{ $summary['has_risk']
                    ? __(':count loan(s) delinquent or at warning stage', ['count' => $summary['delinquent_count']])
                    : __('No delinquent guaranteed loans') }}
            </p>
        </div>
    </div>

    {{ $this->table }}
</div>
