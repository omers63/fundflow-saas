@php
    $summary = $summary ?? [];
    $currency = $summary['currency'] ?? null;
    $balances = $summary['balances'] ?? [];
    $cycle = $summary['cycle'] ?? [];
    $arrears = $summary['arrears'] ?? [];
    $loan = $summary['loan'] ?? null;
    $household = $summary['household'] ?? [];
    $cycleTone = $cycle['tone'] ?? 'gray';
@endphp

<div class="ff-member-workspace-summary mb-3 w-full max-w-none space-y-3">
    <section
        class="overflow-hidden rounded-xl border border-gray-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-[1fr_auto_1fr] lg:items-center">
            <div class="grid grid-cols-2 gap-2">
                <a href="{{ $balances['cash']['url'] ?? '#' }}" @class([
                    'block rounded-lg border border-gray-200/90 px-3 py-2.5 transition hover:bg-gray-50 dark:border-white/10 dark:hover:bg-gray-800/80',
                    'pointer-events-none opacity-70' => empty($balances['cash']['url']),
                ])>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('Cash') }}
                    </p>
                    <p @class([
                        'mt-0.5 text-xl font-extrabold tabular-nums tracking-tight',
                        ($balances['cash']['negative'] ?? false)
                        ? 'text-rose-600 dark:text-rose-400'
                        : 'text-emerald-600 dark:text-emerald-400',
                    ])>
                        <x-member::amount :value="$balances['cash']['amount'] ?? 0" :currency="$currency" />
                    </p>
                </a>
                <a href="{{ $balances['fund']['url'] ?? '#' }}" @class([
                    'block rounded-lg border border-gray-200/90 px-3 py-2.5 transition hover:bg-gray-50 dark:border-white/10 dark:hover:bg-gray-800/80',
                    'pointer-events-none opacity-70' => empty($balances['fund']['url']),
                ])>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('Fund') }}
                    </p>
                    <p @class([
                        'mt-0.5 text-xl font-extrabold tabular-nums tracking-tight',
                        ($balances['fund']['negative'] ?? false)
                        ? 'text-rose-600 dark:text-rose-400'
                        : 'text-indigo-600 dark:text-indigo-400',
                    ])>
                        <x-member::amount :value="$balances['fund']['amount'] ?? 0" :currency="$currency" />
                    </p>
                </a>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-2">
                @if (filled($cycle['label'] ?? null))
                    <span @class([
                        'inline-flex max-w-full items-center rounded-full px-2.5 py-1 text-[11px] font-semibold',
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' => in_array($cycleTone, ['success'], true),
                        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' => $cycleTone === 'warning',
                        'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300' => $cycleTone === 'violet',
                        'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $cycleTone === 'gray',
                    ])>
                        {{ $cycle['label'] }}
                    </span>
                @endif

                @if ($arrears['visible'] ?? false)
                    <a href="{{ $arrears['cta_url'] ?? '#' }}"
                        class="ff-member-detail-chip ff-member-detail-chip--danger inline-flex max-w-full items-center gap-1.5">
                        <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0" />
                        <span>{{ $arrears['cta_label'] ?? __('Arrears') }}</span>
                    </a>
                @endif

                @if (filled($cycle['url'] ?? null))
                    <a href="{{ $cycle['url'] }}"
                        class="text-[11px] font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                        {{ __('Open cycle') }}
                    </a>
                @endif
            </div>

            <div class="text-center text-[11px] text-gray-500 dark:text-gray-400 sm:text-end">
                <p>{{ __(':monthly monthly', ['monthly' => $summary['monthly_formatted'] ?? '—']) }}</p>
            </div>
        </div>

        @if ($loan !== null || filled($household['parent_name'] ?? null) || ($household['dependents_count'] ?? 0) > 0)
            <div class="space-y-2 border-t border-gray-200/80 px-3 py-2.5 dark:border-white/10">
                @if ($loan !== null)
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                        <p class="text-gray-600 dark:text-gray-300">
                            {{ __('Active loan') }}:
                            <span class="font-semibold text-gray-900 dark:text-white">#{{ $loan['id'] }}</span>
                            · {{ $loan['status_label'] ?? '' }}
                            · {{ (int) ($loan['installments_paid'] ?? 0) }}/{{ (int) ($loan['installments_total'] ?? 0) }}
                            ({{ (int) ($loan['repay_percent'] ?? 0) }}%)
                        </p>
                        @if (filled($loan['url'] ?? null))
                            <a href="{{ $loan['url'] }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                {{ __('View loans') }}
                            </a>
                        @endif
                    </div>
                @endif

                @if (filled($household['parent_name'] ?? null) || ($household['dependents_count'] ?? 0) > 0)
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-gray-500 dark:text-gray-400">
                        @if (filled($household['parent_name'] ?? null))
                            <span>
                                {{ __('Parent') }}:
                                @if (filled($household['parent_url'] ?? null))
                                    <a href="{{ $household['parent_url'] }}"
                                        class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                        {{ $household['parent_name'] }}
                                    </a>
                                @else
                                    {{ $household['parent_name'] }}
                                @endif
                            </span>
                        @endif

                        @foreach ($household['dependents'] ?? [] as $dependent)
                            <span>
                                @if (filled($dependent['url'] ?? null))
                                    <a href="{{ $dependent['url'] }}"
                                        class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                        {{ $dependent['name'] }}
                                    </a>
                                @else
                                    {{ $dependent['name'] }}
                                @endif
                            </span>
                        @endforeach

                        @if (($household['dependents_count'] ?? 0) > count($household['dependents'] ?? []))
                            <span>{{ trans_choice('+ :count more dependent|+ :count more dependents', ($household['dependents_count'] ?? 0) - count($household['dependents'] ?? []), ['count' => ($household['dependents_count'] ?? 0) - count($household['dependents'] ?? [])]) }}</span>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </section>
</div>