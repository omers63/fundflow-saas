@php
    /** @var array<string, mixed> $forecast */
@endphp

<div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
    <div class="rounded-xl border border-emerald-200/80 bg-emerald-50/60 px-3 py-3 shadow-sm dark:border-emerald-800/40 dark:bg-emerald-950/20">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-300">{{ __('Pending inbound') }}</p>
        <p class="mt-1 text-xl font-bold tabular-nums text-emerald-900 dark:text-emerald-100">
            <x-member::amount :value="$forecast['pending_deposit_amount']" :currency="$forecast['currency']" :precision="0" class="inline" />
        </p>
        <p class="mt-1 text-[10px] text-emerald-700/80 dark:text-emerald-300/80">
            {{ trans_choice(':count deposit pending|:count deposits pending', $forecast['pending_deposit_count'], ['count' => $forecast['pending_deposit_count']]) }}
        </p>
    </div>
    <div class="rounded-xl border border-amber-200/80 bg-amber-50/60 px-3 py-3 shadow-sm dark:border-amber-800/40 dark:bg-amber-950/20">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-300">{{ __('Pending outbound') }}</p>
        <p class="mt-1 text-xl font-bold tabular-nums text-amber-900 dark:text-amber-100">
            <x-member::amount :value="$forecast['pending_cash_out_amount']" :currency="$forecast['currency']" :precision="0" class="inline" />
        </p>
        <p class="mt-1 text-[10px] text-amber-700/80 dark:text-amber-300/80">
            {{ trans_choice(':count cash-out pending|:count cash-outs pending', $forecast['pending_cash_out_count'], ['count' => $forecast['pending_cash_out_count']]) }}
        </p>
    </div>
    <div @class([
        'rounded-xl border px-3 py-3 shadow-sm',
        'border-rose-200/80 bg-rose-50/60 dark:border-rose-800/40 dark:bg-rose-950/20' => ($forecast['pending_net_amount'] ?? 0) < 0,
        'border-sky-200/80 bg-sky-50/60 dark:border-sky-800/40 dark:bg-sky-950/20' => ($forecast['pending_net_amount'] ?? 0) >= 0,
    ])>
        <p @class([
            'text-[10px] font-semibold uppercase tracking-wide',
            'text-rose-600 dark:text-rose-300' => ($forecast['pending_net_amount'] ?? 0) < 0,
            'text-sky-600 dark:text-sky-300' => ($forecast['pending_net_amount'] ?? 0) >= 0,
        ])>{{ __('Pending net flow') }}</p>
        <p @class([
            'mt-1 text-xl font-bold tabular-nums',
            'text-rose-900 dark:text-rose-100' => ($forecast['pending_net_amount'] ?? 0) < 0,
            'text-sky-900 dark:text-sky-100' => ($forecast['pending_net_amount'] ?? 0) >= 0,
        ])>
            <x-member::amount :value="$forecast['pending_net_amount']" :currency="$forecast['currency']" :precision="0" class="inline" />
        </p>
        <p @class([
            'mt-1 text-[10px]',
            'text-rose-700/80 dark:text-rose-300/80' => ($forecast['pending_net_amount'] ?? 0) < 0,
            'text-sky-700/80 dark:text-sky-300/80' => ($forecast['pending_net_amount'] ?? 0) >= 0,
        ])>{{ __('Deposits less withdrawals') }}</p>
    </div>
    <div class="rounded-xl border border-violet-200/80 bg-violet-50/60 px-3 py-3 shadow-sm dark:border-violet-800/40 dark:bg-violet-950/20">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">{{ __('Projected cash') }}</p>
        <p class="mt-1 text-xl font-bold tabular-nums text-violet-900 dark:text-violet-100">
            <x-member::amount :value="$forecast['projected_available_cash']" :currency="$forecast['currency']" :precision="0" class="inline" />
        </p>
        <p class="mt-1 text-[10px] text-violet-700/80 dark:text-violet-300/80">
            {{ __('Clearing backlog') }}:
            <x-member::amount :value="$forecast['clearing_backlog_amount']" :currency="$forecast['currency']" :precision="0" class="inline" />
        </p>
    </div>
</div>
