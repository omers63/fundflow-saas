@php
    $amounts = $d['collection_amounts'] ?? null;
    $currency = $d['currency'] ?? 'USD';
    $periodLabel = $d['open_period']['label'] ?? null;
@endphp

@if ($amounts)
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-rose-200/80 bg-rose-50/60 px-3 py-3 shadow-sm dark:border-rose-800/40 dark:bg-rose-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-rose-600 dark:text-rose-300">
                {{ __('Total arrears amount') }}
            </p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-rose-900 dark:text-rose-100">
                <x-member::amount :value="$amounts['arrears_amount']" :currency="$currency" :precision="0" />
            </p>
            @if ($periodLabel)
                <p class="mt-1 text-[10px] text-rose-700/80 dark:text-rose-300/80">{{ __('Before :period', ['period' => $periodLabel]) }}</p>
            @endif
        </div>
        <div class="rounded-xl border border-emerald-200/80 bg-emerald-50/60 px-3 py-3 shadow-sm dark:border-emerald-800/40 dark:bg-emerald-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-300">
                {{ __('Total recovered') }}
            </p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-900 dark:text-emerald-100">
                <x-member::amount :value="$amounts['recovered_amount']" :currency="$currency" :precision="0" />
            </p>
            @if ($periodLabel)
                <p class="mt-1 text-[10px] text-emerald-700/80 dark:text-emerald-300/80">{{ $periodLabel }}</p>
            @endif
        </div>
        <div class="rounded-xl border border-amber-200/80 bg-amber-50/60 px-3 py-3 shadow-sm dark:border-amber-800/40 dark:bg-amber-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-300">
                {{ __('Total yet unrecovered') }}
            </p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-amber-900 dark:text-amber-100">
                <x-member::amount :value="$amounts['unrecovered_amount']" :currency="$currency" :precision="0" />
            </p>
            @if ($periodLabel)
                <p class="mt-1 text-[10px] text-amber-700/80 dark:text-amber-300/80">{{ $periodLabel }}</p>
            @endif
        </div>
    </div>
@endif
