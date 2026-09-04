@php
    use App\Support\Reconciliation\ReconciliationExceptionPresenter;

    $stats = $this->getOpenExceptionQueueStats();
    $counts = $this->getOpenExceptionCountByDomain();
    $activeDomain = $this->queueDomainFilter;

    $kpis = [
        [
            'label' => __('Open queue'),
            'value' => $stats['total'],
            'sub' => __('exceptions'),
            'accent' => 'gray',
        ],
        [
            'label' => __('Critical'),
            'value' => $stats['critical'],
            'sub' => __('severity'),
            'accent' => 'rose',
            'value_class' => 'text-red-900 dark:text-red-100',
        ],
        [
            'label' => __('High severity'),
            'value' => $stats['high'],
            'sub' => __('severity'),
            'accent' => 'amber',
            'value_class' => 'text-amber-900 dark:text-amber-100',
        ],
        [
            'label' => __('Escalated'),
            'value' => $stats['escalated'],
            'sub' => __('open'),
            'accent' => 'gray',
        ],
        [
            'label' => __('Unassigned'),
            'value' => $stats['unassigned'],
            'sub' => __('open'),
            'accent' => 'gray',
        ],
    ];
@endphp

@if ($stats['total'] > 0)
    <div class="ff-recon-queue-summary space-y-2">
        @include('filament.tenant.widgets.partials.insights-kpi-strip', [
            'kpis' => $kpis,
            'compact' => true,
        ])

        @if ($counts !== [])
            <div class="ff-recon-domain-strip flex flex-wrap items-center gap-1.5">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Filter by area') }}</span>
                <button type="button" wire:click="setQueueDomainFilter(null)"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-[11px] font-medium transition',
                        'border-primary-300 bg-primary-50 text-primary-800 dark:border-primary-500/40 dark:bg-primary-500/10 dark:text-primary-200' => $activeDomain === null,
                        'border-gray-200 bg-white text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:bg-transparent dark:text-gray-200 dark:hover:bg-white/10' => $activeDomain !== null,
                    ])>
                    {{ __('All areas') }}
                    <span class="rounded bg-gray-800 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-white dark:bg-white/20">{{ $stats['total'] }}</span>
                </button>
                @foreach ($counts as $domain => $count)
                    <button type="button" wire:click="setQueueDomainFilter(@js($domain))"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-[11px] font-medium transition',
                            'border-primary-300 bg-primary-50 text-primary-800 dark:border-primary-500/40 dark:bg-primary-500/10 dark:text-primary-200' => $activeDomain === $domain,
                            'border-gray-200 bg-white text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:bg-transparent dark:text-gray-200 dark:hover:bg-white/10' => $activeDomain !== $domain,
                        ])>
                        <span>{{ ReconciliationExceptionPresenter::domainLabel($domain) }}</span>
                        <span class="rounded bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-white">{{ $count }}</span>
                    </button>
                @endforeach
            </div>
        @endif
    </div>
@endif
