@php
    $amounts = $d['collection_amounts'] ?? null;
    $currency = $d['currency'] ?? 'USD';
    $periodLabel = $d['open_period']['label'] ?? null;
    $compact = (bool) ($compact ?? false);
@endphp
    
    @if ($amounts)
        <div @class([
            'grid grid-cols-1 sm:grid-cols-3',
            'gap-2' => $compact,
            'gap-3' => !$compact,
        ])>
            <div @class([
                'rounded-xl border border-rose-200/80 bg-rose-50/60 shadow-sm dark:border-rose-800/40 dark:bg-rose-950/20',
                'px-2.5 py-2' => $compact,
                'px-3 py-3' => !$compact,
            ])>
                <p class="text-[10px] font-semibold uppercase tracking-wide text-rose-600 dark:text-rose-300">
                    {{ __('Total arrears amount') }}
                </p>
                <p @class([
                    'font-bold tabular-nums text-rose-900 dark:text-rose-100',
                    'mt-0.5 text-lg leading-tight' => $compact,
                    'mt-1 text-2xl' => !$compact,
                ])>
                    <x-member::amount :value="$amounts['arrears_amount']" :currency="$currency" :precision="0" />
                </p>
                @if ($periodLabel)
                    <p @class([
                        'text-rose-700/80 dark:text-rose-300/80',
                        'mt-0.5 text-[10px]' => $compact,
                        'mt-1 text-[10px]' => !$compact,
                    ])>{{ __('Before :period', ['period' => $periodLabel]) }}</p>
                @endif
            </div>
            <div @class([
                'rounded-xl border border-emerald-200/80 bg-emerald-50/60 shadow-sm dark:border-emerald-800/40 dark:bg-emerald-950/20',
                'px-2.5 py-2' => $compact,
                'px-3 py-3' => !$compact,
            ])>
                <p class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-300">
                    {{ __('Total recovered') }}
                </p>
                <p @class([
                    'font-bold tabular-nums text-emerald-900 dark:text-emerald-100',
                    'mt-0.5 text-lg leading-tight' => $compact,
                    'mt-1 text-2xl' => !$compact,
                ])>
                    <x-member::amount :value="$amounts['recovered_amount']" :currency="$currency" :precision="0" />
                </p>
                @if ($periodLabel)
                    <p @class([
                        'text-emerald-700/80 dark:text-emerald-300/80',
                        'mt-0.5 text-[10px]' => $compact,
                        'mt-1 text-[10px]' => !$compact,
                    ])>{{ $periodLabel }}</p>
                @endif
            </div>
            <div @class([
                'rounded-xl border border-amber-200/80 bg-amber-50/60 shadow-sm dark:border-amber-800/40 dark:bg-amber-950/20',
                'px-2.5 py-2' => $compact,
                'px-3 py-3' => !$compact,
            ])>
                <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-300">
                    {{ __('Total yet unrecovered') }}
                </p>
                <p @class([
                    'font-bold tabular-nums text-amber-900 dark:text-amber-100',
                    'mt-0.5 text-lg leading-tight' => $compact,
                    'mt-1 text-2xl' => !$compact,
                ])>
                    <x-member::amount :value="$amounts['unrecovered_amount']" :currency="$currency" :precision="0" />
                </p>
                @if ($periodLabel)
                    <p @class([
                        'text-amber-700/80 dark:text-amber-300/80',
                        'mt-0.5 text-[10px]' => $compact,
                        'mt-1 text-[10px]' => !$compact,
                    ])>{{ $periodLabel }}</p>
                @endif
            </div>
        </div>
    @endif
