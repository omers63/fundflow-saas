@php
    $summary = $summary ?? [];
@endphp

<div class="rounded-xl border border-amber-200/90 bg-amber-50 px-4 py-3 shadow-sm dark:border-amber-800/60 dark:bg-amber-950/40">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900/50">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5 text-amber-600 dark:text-amber-400" />
            </div>
            <div>
                <h2 class="text-sm font-semibold text-amber-900 dark:text-amber-100">
                    {{ $summary['is_delinquent'] ?? false ? __('Your membership is delinquent') : __('Outstanding payments need attention') }}
                </h2>
                <p class="mt-1 text-xs text-amber-800/90 dark:text-amber-200/80">
                    @if (($summary['overdue_installment_count'] ?? 0) > 0)
                        {{ trans_choice(':count overdue loan installment|:count overdue loan installments', $summary['overdue_installment_count'], ['count' => $summary['overdue_installment_count']]) }}
                    @endif
                    @if (($summary['overdue_installment_count'] ?? 0) > 0 && filled($summary['unpaid_contribution_periods'] ?? []))
                        {{ ' · ' }}
                    @endif
                    @if (filled($summary['unpaid_contribution_periods'] ?? []))
                        {{ __('Unpaid contributions: :periods', ['periods' => implode(', ', $summary['unpaid_contribution_periods'])]) }}
                    @endif
                </p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 sm:shrink-0">
            @if (($summary['overdue_installment_count'] ?? 0) > 0)
                <x-filament::button tag="a" :href="$loansUrl" size="sm" color="warning">
                    {{ __('View loans') }}
                </x-filament::button>
            @endif
            @if (filled($summary['unpaid_contribution_periods'] ?? []))
                <x-filament::button tag="a" :href="$contributionsUrl" size="sm" color="gray">
                    {{ __('View contributions') }}
                </x-filament::button>
            @endif
        </div>
    </div>
</div>
