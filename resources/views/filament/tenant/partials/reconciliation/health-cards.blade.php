@php($health = $this->getHealthSummary())
@php($statusColor = match ($health['status']) {
    'pass' => 'text-emerald-600 dark:text-emerald-400',
    'critical' => 'text-red-600 dark:text-red-400',
    default => 'text-amber-600 dark:text-amber-400',
})

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Fund status') }}</p>
        <p class="mt-1 text-lg font-semibold {{ $statusColor }}">
            {{ $health['status_label'] }}
        </p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Last checked :time', ['time' => $health['last_checked_label']]) }}
        </p>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Open issues') }}</p>
        <p @class([
            'mt-1 text-lg font-semibold tabular-nums',
            'text-red-600 dark:text-red-400' => $health['open_issues'] > 0,
            'text-gray-900 dark:text-white' => $health['open_issues'] === 0,
        ])>
            {{ number_format($health['open_issues']) }}
        </p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            @if ($health['open_issues'] > 0)
                {{ trans_choice(':count critical · :warnings other|:count critical · :warnings others', $health['warning_issues'], [
                    'count' => number_format($health['critical_issues']),
                    'warnings' => number_format($health['warning_issues']),
                ]) }}
                ·
                <button type="button" wire:click="setSideTab('exceptions')"
                    class="font-semibold text-sky-600 hover:underline dark:text-sky-400">{{ __('Review issues') }}</button>
            @else
                {{ __('No open issues') }}
            @endif
        </p>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Bank clearing') }}</p>
        <p @class([
            'mt-1 text-lg font-semibold tabular-nums',
            'text-amber-600 dark:text-amber-400' => $health['pending_bank_clearance'] > 0,
            'text-gray-900 dark:text-white' => $health['pending_bank_clearance'] === 0,
        ])>
            {{ number_format($health['pending_bank_clearance']) }}
        </p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            @if ($health['pending_bank_clearance'] > 0)
                <a href="{{ $this->getBankClearingUrl() }}"
                    class="font-semibold text-sky-600 hover:underline dark:text-sky-400">{{ __('Open bank clearing') }}</a>
            @else
                {{ __('All lines matched') }}
            @endif
        </p>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Automatic checks') }}</p>
        <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">
            {{ $health['next_check_at']->format('H:i') }}
        </p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ $health['next_check_label'] }}
        </p>
    </div>
</div>
