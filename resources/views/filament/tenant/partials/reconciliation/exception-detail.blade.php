@php
use App\Filament\Support\MoneyDisplay;
use App\Support\Reconciliation\ReconciliationExceptionPresenter as Presenter;

$style = Presenter::severityStyle((string) $exception->severity);
$contextItems = Presenter::contextItems($exception);
$fixActions = Presenter::recommendedFixActions($exception, true);
@endphp

<div class="ff-recon-exception-detail space-y-5" wire:key="recon-exception-detail-{{ $exception->id }}">
    <div @class(['rounded-xl border px-4 py-4 sm:px-5', $style['banner']])>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ Presenter::domainLabel((string) $exception->domain) }}
                    · {{ $exception->exception_code }}
                </p>
                <p @class(['mt-1 text-xl font-bold', $style['text']])>
                    {{ Presenter::title($exception) }}
                </p>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                    {{ Presenter::summary($exception) }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <span
                    class="inline-flex items-center rounded-full bg-white/80 px-2.5 py-1 text-xs font-semibold text-gray-800 ring-1 ring-gray-200 dark:bg-gray-900/50 dark:text-gray-100 dark:ring-white/10">
                    {{ Presenter::statusLabel((string) $exception->status) }}
                </span>
                <span
                    class="inline-flex items-center rounded-full bg-white/80 px-2.5 py-1 text-xs font-semibold capitalize ring-1 ring-gray-200 dark:bg-gray-900/50 dark:text-gray-100 dark:ring-white/10">
                    {{ ucfirst((string) $exception->severity) }}
                </span>
            </div>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Amount delta') }}</p>
            <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">
                {{ filled($exception->amount_delta) ? (MoneyDisplay::format((float) $exception->amount_delta) ?? '—') : '—' }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Raised') }}</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                {{ $exception->raised_at?->format('d M Y H:i') ?? '—' }}
            </p>
            <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">{{ $exception->raised_at?->diffForHumans() }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Owner') }}</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                {{ $exception->assignee?->name ?? __('Unassigned') }}
            </p>
            @if ($exception->sla_deadline)
                <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                    {{ __('SLA') }} {{ $exception->sla_deadline->diffForHumans() }}
                </p>
            @endif
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Discrepancy type') }}</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                {{ filled($exception->exception_type) ? ucfirst(str_replace('_', ' ', (string) $exception->exception_type)) : '—' }}
            </p>
            @if (filled($exception->auto_resolve_reason))
                <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">{{ $exception->auto_resolve_reason }}</p>
            @endif
        </div>
    </div>

    <div
        class="rounded-xl border border-primary-200 bg-primary-50/70 px-4 py-3 dark:border-primary-500/30 dark:bg-primary-950/20">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-primary-800 dark:text-primary-200">
            {{ __('Suggested next step') }}</p>
        <p class="mt-1 text-sm text-primary-900 dark:text-primary-100">
            {{ Presenter::recommendedAction($exception) }}
        </p>
    </div>

    @if ($contextItems !== [])
        <section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-white/10 sm:px-5">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Related records') }}</h4>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Open linked members, loans, contributions, or bank clearing to inspect underlying data.') }}
                </p>
            </div>
            <div class="grid gap-2 p-4 sm:grid-cols-2 sm:p-5">
                @foreach ($contextItems as $item)
                    <div class="rounded-lg border border-gray-100 bg-gray-50/80 px-3 py-2 dark:border-white/10 dark:bg-white/5">
                        <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $item['label'] }}</p>
                        @if (filled($item['url'] ?? null))
                            <a href="{{ $item['url'] }}"
                                class="mt-0.5 block text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400">
                                {{ $item['value'] }}
                            </a>
                        @else
                            <p class="mt-0.5 text-sm font-semibold text-gray-900 dark:text-white">{{ $item['value'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
            @if (Presenter::isBankClearingRelated($exception))
                <div class="border-t border-gray-100 px-4 py-3 dark:border-white/10 sm:px-5">
                    <a href="{{ Presenter::bankClearingUrl($exception) }}"
                        class="inline-flex items-center gap-1.5 text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400">
                        <x-heroicon-o-building-library class="h-4 w-4" />
                        {{ __('Open bank clearing workspace') }}
                    </a>
                </div>
            @endif
        </section>
    @endif

    @if (Presenter::hasMemberDriftDiagnostics($exception) && ($driftDiagnostics = Presenter::memberDriftDiagnosticsHtml($exception)))
        <section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-white/10 sm:px-5">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Diagnostics') }}</h4>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Formula components, uncounted ledger legs, and suggested correction for this member pool drift.') }}
                </p>
            </div>
            <div class="p-4 sm:p-5">
                {!! $driftDiagnostics !!}
            </div>
        </section>
    @endif
    
    @if ($fixActions !== [] && Presenter::isActionable($exception))
        <section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-white/10 sm:px-5">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Fix actions') }}</h4>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Run a resolution workflow or open the full detail modal for summary and metadata.') }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2 p-4 sm:p-5">
                    @foreach ($fixActions as $action)
                        @php
            $buttonClass = match ($action['color'] ?? 'gray') {
                'primary' => 'border-primary-300 bg-primary-600 text-white hover:bg-primary-700 dark:border-primary-500/40 dark:bg-primary-600 dark:hover:bg-primary-500',
                'success' => 'border-emerald-300 bg-emerald-600 text-white hover:bg-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-600 dark:hover:bg-emerald-500',
                'warning' => 'border-amber-300 bg-amber-500 text-white hover:bg-amber-600 dark:border-amber-500/40 dark:bg-amber-500 dark:hover:bg-amber-400',
                'info' => 'border-sky-300 bg-sky-600 text-white hover:bg-sky-700 dark:border-sky-500/40 dark:bg-sky-600 dark:hover:bg-sky-500',
                default => 'border-gray-200 bg-white text-gray-800 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:text-white dark:hover:bg-white/5',
            };
                        @endphp
                        @if (($action['type'] ?? '') === 'link' && filled($action['url'] ?? null))
                            <a href="{{ $action['url'] }}"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold shadow-sm {{ $buttonClass }}">
                                @if (filled($action['icon'] ?? null))
                                    <x-dynamic-component :component="$action['icon']" class="h-4 w-4" />
                                @endif
                                {{ $action['label'] }}
                            </a>
                        @elseif (($action['type'] ?? '') === 'action' && filled($action['name'] ?? null))
                            <button type="button" wire:click="runExceptionAction(@js($action['name']))" wire:loading.attr="disabled"
                                wire:target="runExceptionAction"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold shadow-sm {{ $buttonClass }}">
                                @if (filled($action['icon'] ?? null))
                                    <x-dynamic-component :component="$action['icon']" class="h-4 w-4" />
                                @endif
                                {{ $action['label'] }}
                            </button>
                        @endif
                    @endforeach
                </div>
            </section>
    @endif

    @if (filled($exception->resolution_notes))
        <section
            class="rounded-xl border border-emerald-200 bg-emerald-50/70 px-4 py-3 dark:border-emerald-500/30 dark:bg-emerald-950/20">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-800 dark:text-emerald-200">
                {{ __('Resolution notes') }}</p>
            <p class="mt-1 text-sm text-emerald-900 dark:text-emerald-100">{{ $exception->resolution_notes }}</p>
        </section>
    @endif
</div>