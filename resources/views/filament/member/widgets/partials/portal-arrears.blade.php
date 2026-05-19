@props(['arrears'])

@if ($arrears['visible'] ?? false)
    <div
        class="overflow-hidden rounded-xl border border-amber-300/90 bg-gradient-to-br from-amber-50 via-amber-100/40 to-white px-3 py-2.5 shadow-md shadow-emerald-950/15 ring-1 ring-amber-200/80 dark:border-amber-500/40 dark:from-amber-950/90 dark:via-amber-900/70 dark:to-gray-900 dark:ring-amber-500/25">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-2">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0 text-amber-700 dark:text-amber-400" />
                <div>
                    <p class="text-sm font-semibold text-amber-950 dark:text-amber-50">
                        {{ ($arrears['is_delinquent'] ?? false) ? __('Your membership is delinquent') : __('Outstanding payments need attention') }}
                    </p>
                    <p class="mt-0.5 text-xs text-amber-900/85 dark:text-amber-100/90">
                        @if (($arrears['overdue_installments'] ?? 0) > 0)
                            {{ trans_choice(':count overdue installment|:count overdue installments', $arrears['overdue_installments'], ['count' => $arrears['overdue_installments']]) }}
                        @endif
                        @if (($arrears['overdue_installments'] ?? 0) > 0 && filled($arrears['unpaid_periods'] ?? []))
                            ·
                        @endif
                        @if (filled($arrears['unpaid_periods'] ?? []))
                            {{ __('Unpaid contributions: :periods', ['periods' => implode(', ', $arrears['unpaid_periods'])]) }}
                        @endif
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                @if (($arrears['overdue_installments'] ?? 0) > 0)
                    <a href="{{ $arrears['loans_url'] }}"
                        class="inline-flex items-center justify-center rounded-lg bg-gradient-to-r from-amber-600 to-yellow-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:from-amber-500 hover:to-yellow-500">
                        {{ __('View loans') }}
                    </a>
                @endif
                @if (filled($arrears['unpaid_periods'] ?? []))
                    <a href="{{ $arrears['contributions_url'] }}"
                        class="inline-flex items-center justify-center rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 shadow-sm hover:bg-amber-50 dark:border-amber-600 dark:bg-gray-900 dark:text-amber-100">
                        {{ __('View contributions') }}
                    </a>
                @endif
            </div>
        </div>
    </div>
@endif