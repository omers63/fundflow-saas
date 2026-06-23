@php
    $currency = $d['currency'];
    $snapshot = $d['snapshot'] ?? [];
    $status = $d['status'] ?? '';
    $isPending = $status === 'pending';
    $isPreDisburse = in_array($status, ['pending', 'approved', 'partially_disbursed'], true);
    $showRepayProgress = (int) ($snapshot['installments_total'] ?? 0) > 0;
    $showDisburseProgress = (float) ($snapshot['approved'] ?? 0) > 0 || $isPreDisburse;
@endphp

<section
    class="ff-loan-detail-shell overflow-hidden rounded-2xl border border-gray-200/90 bg-gradient-to-br from-white via-slate-50 to-sky-50/60 shadow-sm dark:border-white/10 dark:from-gray-900 dark:via-gray-900/95 dark:to-sky-950/20"
    data-ff-loan-ui="v2"
>
    <x-loan-pipeline-stepper :steps="$d['steps']" />

    <div class="grid gap-4 border-t border-gray-200/80 px-4 py-4 dark:border-white/10 md:grid-cols-[1.1fr_0.9fr] md:items-center">
        <div class="min-w-0">
            @if ($isPending)
                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Amount requested') }}</p>
                <p class="mt-1 text-2xl font-extrabold tracking-tight text-gray-900 dark:text-white sm:text-3xl">
                    <x-member::amount :value="$snapshot['requested'] ?? 0" :currency="$currency" />
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if (filled($snapshot['queue_position'] ?? null))
                        {{ __('Queue #:position', ['position' => $snapshot['queue_position']]) }}
                        @if (filled($snapshot['fund_tier'] ?? null))
                            · {{ $snapshot['fund_tier'] }}
                        @endif
                    @elseif (filled($snapshot['fund_tier'] ?? null))
                        {{ $snapshot['fund_tier'] }}
                    @else
                        {{ $d['status_label'] ?? '' }}
                    @endif
                </p>
            @elseif ($isPreDisburse && ! $showRepayProgress)
                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Approved amount') }}</p>
                <p class="mt-1 text-2xl font-extrabold tracking-tight text-gray-900 dark:text-white sm:text-3xl">
                    <x-member::amount :value="$snapshot['approved'] ?? $snapshot['requested'] ?? 0" :currency="$currency" />
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __(':disbursed disbursed · :remaining remaining', [
                        'disbursed' => $snapshot['disbursed_formatted'] ?? '—',
                        'remaining' => $snapshot['remaining_formatted'] ?? '—',
                    ]) }}
                </p>
            @else
                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Outstanding balance') }}</p>
                <p class="mt-1 text-2xl font-extrabold tracking-tight text-gray-900 dark:text-white sm:text-3xl">
                    <x-member::amount :value="$snapshot['outstanding'] ?? 0" :currency="$currency" />
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if ($showRepayProgress)
                        {{ __(':paid of :total installments paid', [
                            'paid' => $snapshot['installments_paid'] ?? 0,
                            'total' => $snapshot['installments_total'] ?? 0,
                        ]) }}
                        @if ((int) ($snapshot['installments_overdue'] ?? 0) > 0)
                            · <span class="text-rose-600 dark:text-rose-400">{{ trans_choice(':count overdue|:count overdue', (int) $snapshot['installments_overdue'], ['count' => (int) $snapshot['installments_overdue']]) }}</span>
                        @endif
                    @else
                        {{ $d['status_label'] ?? '' }}
                    @endif
                </p>
            @endif
        </div>

        <div class="grid gap-3">
            @if ($showDisburseProgress)
                <div>
                    <div class="mb-1 flex items-center justify-between text-[11px] font-semibold text-gray-500 dark:text-gray-400">
                        <span>{{ __('Disbursement') }}</span>
                        <span class="tabular-nums text-gray-800 dark:text-gray-200">{{ (int) ($snapshot['disburse_percent'] ?? 0) }}%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500 transition-all duration-500"
                            style="width: {{ max(3, (int) ($snapshot['disburse_percent'] ?? 0)) }}%"></div>
                    </div>
                </div>
            @endif

            @if ($showRepayProgress)
                <div>
                    <div class="mb-1 flex items-center justify-between text-[11px] font-semibold text-gray-500 dark:text-gray-400">
                        <span>{{ __('Repayment') }}</span>
                        <span class="tabular-nums text-gray-800 dark:text-gray-200">{{ (int) ($snapshot['repay_percent'] ?? 0) }}%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full rounded-full bg-gradient-to-r from-teal-500 to-emerald-500 transition-all duration-500"
                            style="width: {{ max(3, (int) ($snapshot['repay_percent'] ?? 0)) }}%"></div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($d['next_due'] ?? null || ($d['guarantor'] ?? null) || ($d['is_emergency'] ?? false) || (float) ($snapshot['legacy_repayment_total'] ?? 0) > 0 || (filled($snapshot['queue_url'] ?? null) && $isPending))
        <div class="flex flex-wrap gap-2 border-t border-gray-200/80 px-4 py-3 dark:border-white/10">
            @if ($d['is_emergency'] ?? false)
                <span class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-[11px] font-semibold text-rose-700 dark:border-rose-500/30 dark:bg-rose-950/40 dark:text-rose-300">
                    {{ __('Emergency loan') }}
                </span>
            @endif

            @if ($d['next_due'] ?? null)
                <span @class([
                    'inline-flex max-w-full items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold',
                    'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/35 dark:bg-amber-950/35 dark:text-amber-200' => $d['next_due']['is_overdue'],
                    'border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-gray-800/70 dark:text-gray-200' => ! $d['next_due']['is_overdue'],
                ])>
                    <x-heroicon-o-calendar-days class="h-3.5 w-3.5 shrink-0" />
                    <span>
                        {{ __('Next EMI') }}
                        <x-member::amount :value="$d['next_due']['amount']" :currency="$currency" class="inline font-semibold" />
                        · {{ $d['next_due']['date'] }}
                    </span>
                    @if ($d['next_due']['is_overdue'])
                        <span class="text-rose-700 dark:text-rose-300">{{ __('Overdue') }}</span>
                    @endif
                </span>
            @endif

            @if ($d['guarantor'] ?? null)
                <span class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-[11px] font-semibold text-gray-700 dark:border-white/10 dark:bg-gray-800/70 dark:text-gray-200">
                    <x-heroicon-o-user-group class="h-3.5 w-3.5 shrink-0" />
                    <span>
                        {{ __('Guarantor') }}: {{ $d['guarantor']['name'] }}
                        @if ($d['guarantor']['released'])
                            · <span class="text-emerald-600 dark:text-emerald-400">{{ __('Released') }}</span>
                        @elseif ($d['guarantor']['liability_transferred'])
                            · <span class="text-rose-600 dark:text-rose-400">{{ __('Liability transferred') }}</span>
                        @endif
                    </span>
                </span>
            @endif

            @if ((float) ($snapshot['legacy_repayment_total'] ?? 0) > 0)
                <span class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-[11px] font-semibold text-gray-700 dark:border-white/10 dark:bg-gray-800/70 dark:text-gray-200">
                    <x-heroicon-o-receipt-refund class="h-3.5 w-3.5 shrink-0" />
                    <span>
                        {{ __('Imported repayments') }}:
                        <x-member::amount :value="$snapshot['legacy_repayment_total']" :currency="$currency" :compact="true" class="inline font-semibold" />
                    </span>
                </span>
            @endif

            @if (filled($snapshot['queue_url'] ?? null) && $isPending)
                <a href="{{ $snapshot['queue_url'] }}" class="inline-flex items-center gap-1 rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-[11px] font-semibold text-sky-700 transition hover:bg-sky-100 dark:border-sky-500/30 dark:bg-sky-950/40 dark:text-sky-200 dark:hover:bg-sky-950/60">
                    {{ $snapshot['queue_label'] ?? __('Open queue') }}
                    <x-heroicon-o-arrow-right class="h-3.5 w-3.5 rtl:rotate-180" />
                </a>
            @endif
        </div>
    @endif
</section>
