@php
    $d = $this->getData();
    $pollingInterval = method_exists($this, 'getPollingInterval') ? $this->getPollingInterval() : null;
    $currency = $d['currency'] ?? null;
    $snapshot = $d['snapshot'] ?? [];
    $metrics = $d['metrics'] ?? [];
    $hasLoanProgress = (int) ($snapshot['installments_total'] ?? 0) > 0;
    $hasFundProgress = $snapshot['fund_minimum_pct'] !== null;
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1" @if (filled($pollingInterval)) wire:poll.{{ $pollingInterval }} @endif>
    @if (empty($d))
        <div class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            {{ __('Loading member insights…') }}
        </div>
    @else
        <section
            class="ff-member-detail-shell overflow-hidden rounded-2xl border border-gray-200/90 bg-gradient-to-br from-white via-slate-50 to-teal-50/50 shadow-sm dark:border-white/10 dark:from-gray-900 dark:via-gray-900/95 dark:to-teal-950/20"
            data-ff-member-ui="v3"
        >
            <x-member-lifecycle-stepper :steps="$d['steps']" />

            <div class="grid gap-4 border-t border-gray-200/80 px-4 py-4 dark:border-white/10 lg:grid-cols-[1.1fr_0.9fr] lg:items-start">
                <div class="min-w-0 space-y-3">
                    <div>
                        <p @class([
                            'text-[11px] font-semibold uppercase tracking-wide',
                            'text-rose-600 dark:text-rose-400' => ($snapshot['status_tone'] ?? '') === 'danger',
                            'text-amber-600 dark:text-amber-400' => in_array($snapshot['status_tone'] ?? '', ['amber', 'warning'], true),
                            'text-sky-600 dark:text-sky-400' => ($snapshot['status_tone'] ?? '') === 'sky',
                            'text-emerald-600 dark:text-emerald-400' => ($snapshot['status_tone'] ?? 'success') === 'success',
                        ])>
                            {{ $snapshot['status_title'] ?? __('Member overview') }}
                        </p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            {{ __(':monthly monthly · :cycle', [
                                'monthly' => $snapshot['monthly_formatted'] ?? '—',
                                'cycle' => $snapshot['cycle_summary'] ?? $d['cycle']['status_label'] ?? '—',
                            ]) }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <a href="{{ $d['balances']['cash']['url'] ?? '#' }}"
                            @class([
                                'block rounded-xl border border-gray-200/90 bg-white/80 px-3 py-2.5 transition hover:bg-white dark:border-white/10 dark:bg-gray-900/50 dark:hover:bg-gray-900/70',
                                'pointer-events-none opacity-70' => empty($d['balances']['cash']['url']),
                            ])>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Cash') }}</p>
                            <p @class([
                                'mt-0.5 text-xl font-extrabold tabular-nums tracking-tight',
                                $d['balances']['cash']['negative']
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : 'text-emerald-600 dark:text-emerald-400',
                            ])>
                                <x-member::amount :value="$d['balances']['cash']['amount']" :currency="$currency" />
                            </p>
                        </a>
                        <a href="{{ $d['balances']['fund']['url'] ?? '#' }}"
                            @class([
                                'block rounded-xl border border-gray-200/90 bg-white/80 px-3 py-2.5 transition hover:bg-white dark:border-white/10 dark:bg-gray-900/50 dark:hover:bg-gray-900/70',
                                'pointer-events-none opacity-70' => empty($d['balances']['fund']['url']),
                            ])>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Fund') }}</p>
                            <p @class([
                                'mt-0.5 text-xl font-extrabold tabular-nums tracking-tight',
                                $d['balances']['fund']['negative']
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : 'text-indigo-600 dark:text-indigo-400',
                            ])>
                                <x-member::amount :value="$d['balances']['fund']['amount']" :currency="$currency" />
                            </p>
                        </a>
                    </div>

                    @if (($d['arrears']['visible'] ?? false) || filled($snapshot['status_cta_url'] ?? null))
                        <div class="flex flex-wrap gap-2">
                            @if ($d['arrears']['visible'] ?? false)
                                <a href="{{ $d['arrears']['cta_url'] }}"
                                    class="ff-member-detail-chip ff-member-detail-chip--danger inline-flex max-w-full items-center gap-1.5">
                                    <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0" />
                                    <span>{{ $d['arrears']['cta_label'] }}</span>
                                </a>
                            @endif
                            @if (filled($snapshot['status_cta_url'] ?? null))
                                <a href="{{ $snapshot['status_cta_url'] }}"
                                    class="ff-member-detail-chip ff-member-detail-chip--link inline-flex items-center gap-1">
                                    {{ $snapshot['status_cta_label'] }}
                                    <x-heroicon-o-arrow-right class="h-3.5 w-3.5 rtl:rotate-180" />
                                </a>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="space-y-3">
                    @if ($hasFundProgress)
                        <div>
                            <div class="mb-1 flex items-center justify-between text-[11px] font-semibold text-gray-500 dark:text-gray-400">
                                <span>{{ __('Fund vs monthly') }}</span>
                                <span class="tabular-nums text-gray-800 dark:text-gray-200">{{ (int) $snapshot['fund_minimum_pct'] }}%</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500 transition-all duration-500"
                                    style="width: {{ min(100, (int) $snapshot['fund_minimum_pct']) }}%"></div>
                            </div>
                        </div>
                    @endif

                    @if ($hasLoanProgress)
                        <div>
                            <div class="mb-1 flex items-center justify-between text-[11px] font-semibold text-gray-500 dark:text-gray-400">
                                <span>{{ __('Loan repayment') }}</span>
                                <span class="tabular-nums text-gray-800 dark:text-gray-200">
                                    {{ (int) ($snapshot['installments_paid'] ?? 0) }}/{{ (int) ($snapshot['installments_total'] ?? 0) }}
                                </span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                <div class="h-full rounded-full bg-gradient-to-r from-violet-500 to-indigo-500 transition-all duration-500"
                                    style="width: {{ max(3, (int) ($snapshot['repay_percent'] ?? 0)) }}%"></div>
                            </div>
                        </div>
                    @elseif ($d['loan'] === null)
                        <div class="rounded-xl border border-dashed border-gray-200/90 px-3 py-2.5 dark:border-white/10">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Loan eligibility') }}</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                                {{ $d['eligibility']['eligible'] ? __('Eligible to apply') : __('Not eligible') }}
                            </p>
                        </div>
                    @endif

                    <div class="rounded-xl border border-gray-200/80 bg-white/70 p-3 dark:border-white/10 dark:bg-gray-900/40">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Recent activity') }}</p>
                            <a href="{{ $d['cycle']['cycle_url'] ?? '#' }}" class="text-[10px] font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                {{ __('Open cycle') }}
                            </a>
                        </div>
                        <ul class="space-y-2">
                            @forelse (array_slice($d['recent_activity'], 0, 4) as $tx)
                                <li class="flex items-start justify-between gap-2 text-xs">
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-gray-800 dark:text-gray-200">{{ $tx['description'] }}</p>
                                        <p class="text-[10px] text-gray-400">{{ $tx['transacted_at'] }}</p>
                                    </div>
                                    <span @class(['shrink-0 font-semibold tabular-nums', $tx['signed_class']])>
                                        <x-member::amount :value="$tx['amount']" :currency="$currency" />
                                    </span>
                                </li>
                            @empty
                                <li class="text-[11px] text-gray-400">{{ __('No ledger activity yet') }}</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>

            @if ($metrics !== [] || filled($d['quick_links'] ?? []))
                <div class="flex flex-wrap gap-2 border-t border-gray-200/80 px-4 py-3 dark:border-white/10">
                    @foreach ($metrics as $metric)
                        @if (filled($metric['url'] ?? null))
                            <a href="{{ $metric['url'] }}" class="ff-member-detail-chip ff-member-detail-chip--muted">
                                <span class="text-gray-500 dark:text-gray-400">{{ $metric['label'] }}:</span>
                                <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $metric['value'] }}</span>
                            </a>
                        @else
                            <span class="ff-member-detail-chip ff-member-detail-chip--muted">
                                <span class="text-gray-500 dark:text-gray-400">{{ $metric['label'] }}:</span>
                                <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $metric['value'] }}</span>
                            </span>
                        @endif
                    @endforeach

                    @foreach ($d['quick_links'] ?? [] as $link)
                        <a href="{{ $link['url'] }}" title="{{ $link['label'] }}"
                            class="ff-member-detail-chip ff-member-detail-chip--muted inline-flex items-center gap-1">
                            <x-dynamic-component :component="$link['icon']" class="h-3.5 w-3.5" />
                            <span>{{ $link['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        @if (count($d['household']['dependents'] ?? []) > 0 || filled($d['member']['parent_url'] ?? null))
            <div class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Household') }}</h4>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @if (filled($d['member']['parent_url'] ?? null))
                        <a href="{{ $d['member']['parent_url'] }}"
                            class="flex items-center gap-2 px-3 py-2 text-xs transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                            <x-heroicon-o-user class="h-4 w-4 text-sky-500" />
                            <span>{{ __('Parent') }}: <strong>{{ $d['member']['parent_name'] }}</strong></span>
                        </a>
                    @endif
                    @foreach ($d['household']['dependents'] as $dependent)
                        <a href="{{ $dependent['edit_url'] }}"
                            class="flex items-center justify-between gap-2 px-3 py-2 text-xs transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                            <span class="font-medium text-gray-900 dark:text-white">{{ $dependent['name'] }}</span>
                            <span class="text-[10px] text-gray-400">{{ $dependent['number'] }} · {{ $dependent['status'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
