@props(['cycle', 'fundSummary', 'loanCard', 'eligibility'])

<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
    <div class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-arrow-path class="h-4 w-4 text-sky-500" />
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    {{ __('Open cycle') }}</h3>
            </div>
            <span @class([
                'rounded px-1.5 py-0.5 text-[9px] font-bold uppercase',
                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => ($cycle['status_key'] ?? '') === 'posted',
                'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => in_array($cycle['status_key'] ?? '', ['exempt', 'short'], true),
                'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200' => in_array($cycle['status_key'] ?? '', ['ready', 'waiting'], true),
                'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => ($cycle['status_key'] ?? '') === 'na',
            ])>{{ $cycle['status_label'] }}</span>
        </div>
        <div class="space-y-2 px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">
            <div class="flex justify-between gap-2">
                <span>{{ __('Period') }}</span>
                <span class="font-semibold text-gray-900 dark:text-white">{{ $cycle['period_label'] }}</span>
            </div>
            <div class="flex justify-between gap-2">
                <span>{{ __('Required cash') }}</span>
                <span class="font-semibold tabular-nums">{{ $cycle['required_cash'] }}</span>
            </div>
            @if (($fundSummary['fund_minimum_pct'] ?? null) !== null)
                <div>
                    <div class="mb-0.5 flex justify-between text-[10px] text-gray-500 dark:text-gray-400">
                        <span>{{ __('Fund vs monthly') }}</span>
                        <span class="font-semibold">{{ $fundSummary['fund_minimum_pct'] }}%</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                        <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500"
                            style="width: {{ min(100, $fundSummary['fund_minimum_pct']) }}%"></div>
                    </div>
                </div>
            @endif
            <a href="{{ $cycle['contributions_url'] }}"
                class="inline-block text-[10px] font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                {{ __('View contributions') }} →
            </a>
        </div>
    </div>

    @if ($loanCard)
        <div
            class="overflow-hidden rounded-xl border border-violet-200/80 bg-gradient-to-br from-violet-50 to-indigo-50/60 shadow-sm dark:border-violet-500/25 dark:from-violet-950/30 dark:to-indigo-950/20">
            <div class="flex items-center justify-between gap-2 border-b border-violet-100/80 px-3 py-2 dark:border-violet-500/20">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-currency-dollar class="h-4 w-4 text-violet-600 dark:text-violet-400" />
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-violet-800 dark:text-violet-200">
                        {{ __('Active loan') }}</h3>
                </div>
                <span class="text-[10px] font-medium text-violet-700 dark:text-violet-300">{{ $loanCard['status_label'] }}</span>
            </div>
            <div class="space-y-2 px-3 py-2.5">
                <p class="text-xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $loanCard['outstanding'] }}</p>
                <div>
                    <div class="mb-0.5 flex justify-between text-[10px] text-gray-500 dark:text-gray-400">
                        <span>{{ __('Repayment schedule') }}</span>
                        <span>{{ $loanCard['installments'] }} · {{ $loanCard['repay_percent'] }}%</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-white/60 dark:bg-gray-800/60">
                        <div class="h-full rounded-full bg-gradient-to-r from-violet-500 to-indigo-500"
                            style="width: {{ max(4, $loanCard['repay_percent']) }}%"></div>
                    </div>
                </div>
                @if (($loanCard['overdue_count'] ?? 0) > 0)
                    <p class="text-[10px] font-semibold text-rose-600 dark:text-rose-400">
                        {{ trans_choice(':count overdue installment|:count overdue installments', $loanCard['overdue_count'], ['count' => $loanCard['overdue_count']]) }}
                    </p>
                @endif
                <a href="{{ $loanCard['view_url'] }}"
                    class="inline-block text-[10px] font-medium text-violet-700 hover:underline dark:text-violet-300">
                    {{ __('View loan') }} →
                </a>
            </div>
        </div>
    @else
        <div
            class="overflow-hidden rounded-xl border border-gray-200/80 bg-white px-3 py-2.5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-sparkles class="h-4 w-4 text-teal-500" />
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    {{ __('Loan eligibility') }}</h3>
            </div>
            <p @class([
                'mt-2 text-sm font-semibold',
                ($eligibility['eligible'] ?? false) ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-700 dark:text-gray-300',
            ])>
                {{ ($eligibility['eligible'] ?? false) ? __('Eligible to apply') : __('Not eligible') }}
            </p>
            @if (! ($eligibility['eligible'] ?? false) && filled($eligibility['reason'] ?? null))
                <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ $eligibility['reason'] }}</p>
            @endif
            @if ($eligibility['eligible'] ?? false)
                <p class="mt-1 text-[10px] text-gray-500 dark:text-gray-400">
                    {{ __('Up to :amount', ['amount' => $eligibility['max_amount']]) }}</p>
            @endif
            <p class="mt-2 text-[10px] text-gray-400 dark:text-gray-500">
                {{ __('Total posted') }}: {{ $fundSummary['contributions_total'] }}
                · {{ $fundSummary['contributions_count'] }} {{ __('payments') }}
            </p>
        </div>
    @endif
</div>
