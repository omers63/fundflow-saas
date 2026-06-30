@php
    use App\Support\Reconciliation\ReconciliationExceptionPresenter;

    $stats = $this->getOpenExceptionQueueStats();
    $counts = $this->getOpenExceptionCountByDomain();
    $activeDomain = $this->queueDomainFilter;
@endphp

@if ($stats['total'] > 0)
    <div class="ff-recon-queue-summary mb-4 space-y-3">
        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-xl border border-gray-200 bg-gray-50/80 px-3 py-2 dark:border-white/10 dark:bg-white/5">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Open queue') }}</p>
                <p class="mt-0.5 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($stats['total']) }}</p>
            </div>
            <div class="rounded-xl border border-red-200 bg-red-50/70 px-3 py-2 dark:border-red-500/30 dark:bg-red-950/20">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-red-700 dark:text-red-300">{{ __('Critical') }}</p>
                <p class="mt-0.5 text-lg font-semibold tabular-nums text-red-900 dark:text-red-100">{{ number_format($stats['critical']) }}</p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50/70 px-3 py-2 dark:border-amber-500/30 dark:bg-amber-950/20">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-800 dark:text-amber-200">{{ __('High severity') }}</p>
                <p class="mt-0.5 text-lg font-semibold tabular-nums text-amber-900 dark:text-amber-100">{{ number_format($stats['high']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-gray-900/60">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Escalated') }}</p>
                <p class="mt-0.5 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($stats['escalated']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-gray-900/60">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Unassigned') }}</p>
                <p class="mt-0.5 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($stats['unassigned']) }}</p>
            </div>
        </div>

        @if ($counts !== [])
            <div class="ff-recon-domain-strip flex flex-wrap items-center gap-2">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Filter by area') }}:</span>
                <button type="button" wire:click="setQueueDomainFilter(null)"
                    @class([
                        'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium transition',
                        'border-primary-300 bg-primary-50 text-primary-800 dark:border-primary-500/40 dark:bg-primary-500/10 dark:text-primary-200' => $activeDomain === null,
                        'border-gray-200 bg-gray-50 text-gray-700 hover:bg-gray-100 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10' => $activeDomain !== null,
                    ])>
                    {{ __('All areas') }}
                    <span class="rounded-full bg-gray-800 px-1.5 py-0.5 text-[10px] font-semibold text-white dark:bg-white/20">{{ $stats['total'] }}</span>
                </button>
                @foreach ($counts as $domain => $count)
                    <button type="button" wire:click="setQueueDomainFilter(@js($domain))"
                        @class([
                            'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium transition',
                            'border-primary-300 bg-primary-50 text-primary-800 dark:border-primary-500/40 dark:bg-primary-500/10 dark:text-primary-200' => $activeDomain === $domain,
                            'border-gray-200 bg-gray-50 text-gray-700 hover:bg-gray-100 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10' => $activeDomain !== $domain,
                        ])>
                        <span>{{ ReconciliationExceptionPresenter::domainLabel($domain) }}</span>
                        <span class="rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $count }}</span>
                    </button>
                @endforeach
            </div>
        @endif
    </div>
@endif
