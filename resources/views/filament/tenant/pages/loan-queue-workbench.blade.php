<x-filament-panels::page>
    @php
$kpis = $this->getQueueKpis();
$currency = \App\Models\Tenant\Setting::get('general', 'currency', 'USD');
$money = fn($amount) => \App\Filament\Support\MoneyDisplay::format((float) $amount, $currency, precision: 0) ?? number_format((float) $amount);
    @endphp

    <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([
    ['label' => __('Intake'), 'value' => (string) $kpis['intake'], 'sub' => __('Awaiting triage'), 'accent' => 'text-amber-600 dark:text-amber-400'],
    ['label' => __('In tier queues'), 'value' => (string) $kpis['queued'], 'sub' => __('Approved, waiting'), 'accent' => 'text-sky-600 dark:text-sky-400'],
    ['label' => __('Queued demand'), 'value' => $money($kpis['queued_demand']), 'sub' => __('Remaining to fund'), 'accent' => 'text-violet-600 dark:text-violet-400'],
    ['label' => __('Master fund'), 'value' => $money($kpis['disbursable']), 'sub' => __('On hand (shared across tiers)'), 'accent' => $kpis['disbursable'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500'],
    ['label' => __('Ready to process'), 'value' => (string) $kpis['process'], 'sub' => $kpis['process'] > 0 ? __('Fundable now') : __('Waiting on pool headroom'), 'accent' => $kpis['process'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500'],
    ['label' => __('Running'), 'value' => (string) $kpis['running'], 'sub' => __('Loans in repayment'), 'accent' => 'text-teal-600 dark:text-teal-400'],
] as $kpi)
                <div class="rounded-xl border border-gray-200/80 bg-white px-3 py-2 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <p class="m-0 text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ $kpi['label'] }}</p>
                    <p class="m-0 text-lg font-bold {{ $kpi['accent'] }}">{{ $kpi['value'] }}</p>
                    <p class="m-0 text-xs text-gray-500">{{ $kpi['sub'] }}</p>
                </div>
        @endforeach
    </div>

    <div class="ff-tenant-tab-pills mb-4 flex flex-wrap gap-2">
        @foreach ($this->getTabLabels() as $tab => $label)
            <button type="button" wire:click="setQueueTab('{{ $tab }}')" @class([
        'ff-tenant-tab-pills__item',
        'ff-tenant-tab-pills__item--active' => $queueTab === $tab,
    ])>
                <x-ff-tab-pill-label :label="$label" :key="$tab" />
            </button>
        @endforeach
    </div>

    @if ($queueTab === 'tiers')
                @php
        $tierCards = $this->getTierQueues();
        $tierSummary = $this->getTierQueueSummary($tierCards);
                @endphp
                <div class="space-y-3">
                    @forelse ($tierCards as $card)
                                                    @php
                        $tier = $card['tier'];
                        $allocated = max(0.0, (float) $card['allocated']);
                        $cardQueuedRemaining = array_sum(array_column($card['loans'], 'remaining'));
                        $cardRunningOutstanding = array_sum(array_column($card['running'], 'outstanding'));
                                                    @endphp
                                                    <details
                                                        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
                                                        @if (count($card['loans']) > 0 || count($card['running']) > 0) open @endif
                                                    >
                                                        @php
                        $queuedDemand = max(0.0, (float) $cardQueuedRemaining);
                        $runningCommitted = max(0.0, (float) $card['committed'] - $queuedDemand);
                        $headroom = max(0.0, (float) $card['available']);
                        $poolBase = max($allocated, 0.01);
                        $runningPct = min(100, ($runningCommitted / $poolBase) * 100);
                        $queuedPct = min(100 - $runningPct, ($queuedDemand / $poolBase) * 100);
                        $usedPct = min(100, (((float) $card['committed']) / $poolBase) * 100);
                        $headroomPct = max(0, 100 - $runningPct - $queuedPct);
                        $overCommitted = (float) $card['committed'] > $allocated + 0.005;
                        $heatTone = match (true) {
                            $overCommitted || $usedPct >= 95 => 'danger',
                            $usedPct >= 70 => 'warning',
                            default => 'ok',
                        };

                        $repayWeight = 0.0;
                        $repayWeighted = 0.0;
                        foreach ($card['running'] as $runningRow) {
                            $weight = max(0.01, (float) ($runningRow['loan']->amount_approved ?? 0));
                            $repayWeight += $weight;
                            $repayWeighted += $weight * (int) $runningRow['repay_percent'];
                        }
                        $repayProgressPct = $repayWeight > 0
                            ? (int) round($repayWeighted / $repayWeight)
                            : 0;
                        $hasRunningRepayment = count($card['running']) > 0 && $runningPct > 0;
                                                        @endphp
                                                        <summary class="ff-tier-heat-summary cursor-pointer list-none px-3 py-2.5 [&::-webkit-details-marker]:hidden">
                                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                                <span class="flex min-w-0 flex-wrap items-center gap-2">
                                                                    @if ($tier->isEmergency())
                                                                        <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-rose-500" />
                                                                    @else
                                                                        <x-heroicon-o-queue-list class="h-4 w-4 shrink-0 text-gray-400" />
                                                                    @endif
                                                                    <span class="text-sm font-semibold">{{ $tier->label }}</span>
                                                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                                                        {{ trans_choice(':count queued|:count queued', count($card['loans']), ['count' => count($card['loans'])]) }}
                                                                    </span>
                                                                    @if (count($card['running']) > 0)
                                                                        <span class="rounded-full bg-teal-100 px-2 py-0.5 text-xs text-teal-700 dark:bg-teal-900/40 dark:text-teal-300">
                                                                            {{ trans_choice(':count running|:count running', count($card['running']), ['count' => count($card['running'])]) }}
                                                                        </span>
                                                                    @endif
                                                                </span>
                                                                <span class="flex flex-wrap items-center gap-1.5">
                                                                    @if ($hasRunningRepayment)
                                                                        <span class="ff-tier-heat__badge ff-tier-heat__badge--repaid">
                                                                            {{ __(':percent% repaid', ['percent' => $repayProgressPct]) }}
                                                                        </span>
                                                                    @endif
                                                                    <span @class([
                            'ff-tier-heat__badge',
                            'ff-tier-heat__badge--danger' => $heatTone === 'danger',
                            'ff-tier-heat__badge--warning' => $heatTone === 'warning',
                            'ff-tier-heat__badge--ok' => $heatTone === 'ok',
                        ])>
                                                                        {{ __(':percent% used', ['percent' => (int) round($usedPct)]) }}
                                                                    </span>
                                                                </span>
                                                            </div>

                                                            <div
                                                                class="ff-tier-heat mt-2.5"
                                                                data-tone="{{ $heatTone }}"
                                                                role="img"
                                                                aria-label="{{ __('Allocated :allocated · Committed :committed · Tier headroom :disbursable', [
                            'allocated' => $money($card['allocated']),
                            'committed' => $money($card['committed']),
                            'disbursable' => $money($card['disbursable']),
                        ]) . (
                            $hasRunningRepayment
                            ? ' · ' . __(':percent% repaid', ['percent' => $repayProgressPct])
                            : ''
                        ) }}"
                                                            >
                                                                <div class="ff-tier-heat__track">
                                                                    @if ($runningPct > 0)
                                                                        <div
                                                                            class="ff-tier-heat__seg ff-tier-heat__seg--running"
                                                                            style="width: {{ number_format($runningPct, 2, '.', '') }}%"
                                                                            title="{{ __('Running outstanding :amount', ['amount' => $money($cardRunningOutstanding)]) }}"
                                                                        >
                                                                            @if ($hasRunningRepayment)
                                                                                <div
                                                                                    class="ff-tier-heat__repay-fill"
                                                                                    style="width: {{ max(0, min(100, $repayProgressPct)) }}%"
                                                                                ></div>
                                                                            @endif
                                                                        </div>
                                                                    @endif
                                                                    @if ($queuedPct > 0)
                                                                        <div class="ff-tier-heat__seg ff-tier-heat__seg--queued" style="width: {{ number_format($queuedPct, 2, '.', '') }}%"></div>
                                                                    @endif
                                                                    @if ($headroomPct > 0)
                                                                        <div class="ff-tier-heat__seg ff-tier-heat__seg--headroom" style="width: {{ number_format($headroomPct, 2, '.', '') }}%"></div>
                                                                    @endif
                                                                    @if ($overCommitted)
                                                                        <div class="ff-tier-heat__overheat" aria-hidden="true"></div>
                                                                    @endif
                                                                </div>

                                                                <div class="ff-tier-heat__legend">
                                                                    <span class="ff-tier-heat__chip">
                                                                        <span class="ff-tier-heat__swatch ff-tier-heat__swatch--allocated" aria-hidden="true"></span>
                                                                        <span class="ff-tier-heat__chip-label">{{ __('Allocated') }}</span>
                                                                        <span class="ff-tier-heat__chip-value">{{ $money($card['allocated']) }}</span>
                                                                    </span>
                                                                    <span class="ff-tier-heat__chip">
                                                                        <span class="ff-tier-heat__swatch ff-tier-heat__swatch--committed" aria-hidden="true"></span>
                                                                        <span class="ff-tier-heat__chip-label">{{ __('Committed') }}</span>
                                                                        <span class="ff-tier-heat__chip-value">{{ $money($card['committed']) }}</span>
                                                                    </span>
                                                                    <span class="ff-tier-heat__chip">
                                                                        <span class="ff-tier-heat__swatch ff-tier-heat__swatch--headroom" aria-hidden="true"></span>
                                                                        <span class="ff-tier-heat__chip-label">{{ __('Headroom') }}</span>
                                                                        <span class="ff-tier-heat__chip-value">{{ $money($headroom) }}</span>
                                                                    </span>
                                                                    <span class="ff-tier-heat__chip ff-tier-heat__chip--progress">
                                                                        <span class="ff-tier-heat__chip-label">{{ __('Progress') }}</span>
                                                                        <span class="ff-tier-heat__chip-value">{{ (int) round($usedPct) }}%</span>
                                                                    </span>
                                                                    @if ($hasRunningRepayment)
                                                                        <span class="ff-tier-heat__chip ff-tier-heat__chip--repaid">
                                                                            <span class="ff-tier-heat__swatch ff-tier-heat__swatch--repaid" aria-hidden="true"></span>
                                                                            <span class="ff-tier-heat__chip-label">{{ __('Repaid') }}</span>
                                                                            <span class="ff-tier-heat__chip-value">{{ $repayProgressPct }}%</span>
                                                                        </span>
                                                                        <span class="ff-tier-heat__chip">
                                                                            <span class="ff-tier-heat__swatch ff-tier-heat__swatch--outstanding" aria-hidden="true"></span>
                                                                            <span class="ff-tier-heat__chip-label">{{ __('Outstanding') }}</span>
                                                                            <span class="ff-tier-heat__chip-value">{{ $money($cardRunningOutstanding) }}</span>
                                                                        </span>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        </summary>

                                                    <div class="border-t border-gray-100 px-3 py-2 dark:border-gray-700">
                                                            @if (count($card['loans']) === 0 && count($card['running']) === 0)
                                                                <p class="m-0 py-1 text-sm text-gray-500">{{ __('No loans in this tier.') }}</p>
                                                            @endif

                                                            @if (count($card['loans']) > 0)
                                                                <p class="mb-1 mt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ __('Waiting to fund') }}</p>
                                                                <ul class="m-0 list-none divide-y divide-gray-100 p-0 dark:divide-gray-700">
                                                                    @foreach ($card['loans'] as $row)
                                                                        @php $loan = $row['loan']; @endphp
                                                                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                                                            <span class="flex min-w-0 flex-wrap items-center gap-2">
                                                                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-bold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                                                                    {{ $loan->queue_position ?? '—' }}
                                                                                </span>
                                                                                <a href="{{ \App\Filament\Tenant\Resources\Loans\LoanResource::getUrl('view', ['record' => $loan]) }}" class="text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400">
                                                                                    {{ $loan->member?->name ?? __('Loan #:id', ['id' => $loan->id]) }}
                                                                                </a>
                                                                                @if ($loan->is_emergency)
                                                                                    <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">{{ __('Emergency') }}</span>
                                                                                @endif
                                                                                @if ($loan->status === 'partially_disbursed')
                                                                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ \App\Models\Tenant\Loan::statusOptions()['partially_disbursed'] ?? __('Partially disbursed') }}</span>
                                                                                @endif
                                                                            </span>
                                                                            <span class="flex flex-wrap items-center gap-2 text-xs">
                                                                                <span class="font-semibold">{{ __('Remaining :amount', ['amount' => $money($row['remaining'])]) }}</span>
                                                                                @if ($row['coverage'] !== null)
                                                                                    <span class="rounded-full px-2 py-0.5 font-semibold {{ $row['coverage']['full'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' }}">
                                                                                        {{ $row['coverage']['full'] ? __('Full coverage') : __('Partial up to :amount', ['amount' => $money($row['coverage']['amount'])]) }}
                                                                                    </span>
                                                                                @endif
                                                                                <span class="rounded-full px-2 py-0.5 font-semibold {{ $row['projection']['ready_now'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300' }}">
                                                                                    {{ $row['projection']['label'] }}
                                                                                </span>
                                                                            </span>
                                                                        </li>
                                                                    @endforeach
                                                                </ul>
                                                            @endif

                                                            @if (count($card['running']) > 0)
                                                                <p class="mb-1 mt-3 text-[11px] font-semibold uppercase tracking-wide text-teal-600 dark:text-teal-400">{{ __('Running — in repayment') }}</p>
                                                                <ul class="m-0 list-none divide-y divide-gray-100 p-0 dark:divide-gray-700">
                                                                    @foreach ($card['running'] as $row)
                                                                        @php $loan = $row['loan']; @endphp
                                                                        <li class="py-2.5">
                                                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                                                <a href="{{ \App\Filament\Tenant\Resources\Loans\LoanResource::getUrl('view', ['record' => $loan]) }}" class="text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400">
                                                                                    {{ $loan->member?->name ?? __('Loan #:id', ['id' => $loan->id]) }}
                                                                                </a>
                                                                                <span class="text-xs text-gray-500">
                                                                                    {{ __('Outstanding :amount', ['amount' => $money($row['outstanding'])]) }}
                                                                                </span>
                                                                            </div>
                                                                            <div class="mt-2 flex items-center gap-2">
                                                                                <div class="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                                                                    <div class="h-full rounded-full bg-teal-500 transition-all" style="width: {{ max(3, $row['repay_percent']) }}%"></div>
                                                                                </div>
                                                                                <span class="shrink-0 text-[11px] font-semibold tabular-nums text-teal-700 dark:text-teal-300">
                                                                                    {{ __(':paid/:total EMIs · :percent%', [
                                    'paid' => $row['installments_paid'],
                                    'total' => $row['installments_total'],
                                    'percent' => $row['repay_percent'],
                                ]) }}
                                                                                </span>
                                                                            </div>
                                                                        </li>
                                                                    @endforeach
                                                                </ul>
                                                            @endif

                                                            @if (count($card['loans']) > 0 || count($card['running']) > 0)
                                                                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-2 text-xs font-semibold dark:border-gray-700">
                                                                    <span class="text-gray-500">{{ __('Tier total') }}</span>
                                                                    <span class="flex flex-wrap gap-3 tabular-nums">
                                                                        @if (count($card['loans']) > 0)
                                                                            <span>{{ __('Queued remaining :amount', ['amount' => $money($cardQueuedRemaining)]) }}</span>
                                                                        @endif
                                                                        @if (count($card['running']) > 0)
                                                                            <span class="text-teal-700 dark:text-teal-300">{{ __('Running outstanding :amount', ['amount' => $money($cardRunningOutstanding)]) }}</span>
                                                                        @endif
                                                                    </span>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </details>
                    @empty
                        <div class="rounded-xl border border-gray-200/80 bg-white px-3 py-4 text-sm text-gray-500 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                            {{ __('No active fund tiers configured. Set up fund tiers to build per-tier loan queues.') }}
                        </div>
                    @endforelse

                    @if (count($tierCards) > 0)
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-gray-200/80 bg-gray-50 px-3 py-2.5 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900/40">
                            <span class="font-semibold text-gray-700 dark:text-gray-200">{{ __('All tiers') }}</span>
                            <span class="flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold tabular-nums text-gray-600 dark:text-gray-300">
                                <span>{{ trans_choice(':count queued|:count queued', $tierSummary['queued_count'], ['count' => $tierSummary['queued_count']]) }} · {{ $money($tierSummary['queued_remaining']) }}</span>
                                <span class="text-teal-700 dark:text-teal-300">{{ trans_choice(':count running|:count running', $tierSummary['running_count'], ['count' => $tierSummary['running_count']]) }} · {{ $money($tierSummary['running_outstanding']) }}</span>
                            </span>
                        </div>
                    @endif
                </div>
    @else
        {{ $this->table }}
    @endif
</x-filament-panels::page>
