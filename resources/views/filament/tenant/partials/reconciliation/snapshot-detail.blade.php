@php
    use App\Support\Reconciliation\ReconciliationSnapshotPresenter as Presenter;

    $report = $snapshot->report ?? [];
    $checks = $report['checks'] ?? [];
    $pipeline = $report['pipeline'] ?? ($snapshot->summary['pipeline'] ?? []);
    $control = $report['control_layer'] ?? ($snapshot->summary['control_layer'] ?? []);
    $period = $report['period_metrics'] ?? [];
    $currency = Presenter::currency();
    $severityCounts = collect($checks)->countBy(fn (array $check): string => strtolower((string) ($check['severity'] ?? 'unknown')));
    $orderedChecks = Presenter::orderedChecks($checks);
    $failingChecks = collect($orderedChecks)->filter(fn (array $row): bool => in_array(
        strtolower((string) ($row['check']['severity'] ?? '')),
        ['critical', 'warning', 'fail'],
        true,
    ));
@endphp

<div class="ff-recon-snapshot-detail space-y-5" wire:key="recon-snapshot-detail-{{ $snapshot->id }}">
    {{-- Verdict banner --}}
    <div @class([
        'rounded-xl border px-4 py-4 sm:px-5',
        'border-emerald-200 bg-emerald-50/90 dark:border-emerald-500/30 dark:bg-emerald-950/30' => $snapshot->is_passing,
        'border-red-200 bg-red-50/90 dark:border-red-500/30 dark:bg-red-950/30' => ! $snapshot->is_passing,
    ])>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Snapshot #:id', ['id' => $snapshot->id]) }}
                </p>
                <p @class([
                    'mt-1 text-xl font-bold',
                    'text-emerald-800 dark:text-emerald-200' => $snapshot->is_passing,
                    'text-red-800 dark:text-red-200' => ! $snapshot->is_passing,
                ])>
                    {{ $snapshot->is_passing ? __('Reconciliation passed') : __('Reconciliation found issues') }}
                </p>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                    {{ Presenter::modeLabel($snapshot->mode) }}
                    · {{ __('As of') }} {{ $snapshot->as_of->format('d M Y H:i') }}
                    @if ($snapshot->period_start && $snapshot->period_end)
                        · {{ __('Period') }} {{ $snapshot->period_start->format('d M Y') }}
                        → {{ $snapshot->period_end->format('d M Y') }}
                    @endif
                    @if ($snapshot->createdBy)
                        · {{ __('Run by :name', ['name' => $snapshot->createdBy->name]) }}
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <span class="inline-flex items-center rounded-full bg-white/80 px-2.5 py-1 text-xs font-semibold text-red-700 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-200 dark:ring-red-500/30">
                    {{ __('Critical') }}: {{ number_format($snapshot->critical_issues) }}
                </span>
                <span class="inline-flex items-center rounded-full bg-white/80 px-2.5 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-200 dark:ring-amber-500/30">
                    {{ __('Warnings') }}: {{ number_format($snapshot->warnings) }}
                </span>
            </div>
        </div>
    </div>

    {{-- Context cards --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Checks with issues') }}</p>
            <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($failingChecks->count()) }}</p>
            <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                {{ __(':critical critical · :warning warnings in report', [
                    'critical' => number_format((int) ($severityCounts['critical'] ?? 0)),
                    'warning' => number_format((int) ($severityCounts['warning'] ?? 0)),
                ]) }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Open exception queue') }}</p>
            <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">
                {{ number_format((int) ($control['open_exception_count'] ?? 0)) }}
            </p>
            <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">{{ __('Live queue at snapshot time') }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Bank pipeline') }}</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                {{ number_format((int) ($pipeline['bank_unposted_count'] ?? 0)) }} {{ __('unposted') }}
                · {{ number_format((int) ($pipeline['bank_uncleared_count'] ?? 0)) }} {{ __('uncleared') }}
            </p>
            <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                {!! \App\Filament\Support\MoneyDisplay::html((float) ($pipeline['bank_uncleared_amount'] ?? 0), $currency)?->toHtml() ?? '—' !!}
                {{ __('uncleared amount') }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Period activity') }}</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                @if (filled($period['ledger_lines_in_period'] ?? null))
                    {{ number_format((int) $period['ledger_lines_in_period']) }} {{ __('ledger lines') }}
                @else
                    {{ __('Full book as of run time') }}
                @endif
            </p>
            @if (filled($period['bank_mirrored_in_period'] ?? null))
                <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                    {{ number_format((int) $period['bank_mirrored_in_period']) }} {{ __('bank lines mirrored in period') }}
                </p>
            @endif
        </div>
    </div>

    {{-- Checks --}}
    <section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-white/10 sm:px-5">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Check results') }}</h4>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Expand a check to review metrics, mismatches, and drill-down links.') }}
            </p>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($orderedChecks as $row)
                @php
                    $key = $row['key'];
                    $check = $row['check'];
                    $severity = (string) ($check['severity'] ?? 'unknown');
                    $style = Presenter::severityStyle($severity);
                    $summary = Presenter::checkSummary($key, $check, $currency);
                    $sections = Presenter::checkDetailSections($key, $check);
                    $openByDefault = in_array(strtolower($severity), ['critical', 'warning', 'fail'], true);
                @endphp
                <details
                    class="group px-4 py-3 sm:px-5"
                    @if ($openByDefault) open @endif
                >
                    <summary class="cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $style['badge'] }}">
                                        {{ $style['label'] }}
                                    </span>
                                    <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ Presenter::checkHeadline($key, $check) }}
                                    </span>
                                </div>
                                @if ($summary)
                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $summary }}</p>
                                @elseif (filled($check['note'] ?? null))
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $check['note'] }}</p>
                                @endif
                            </div>
                            <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-180" />
                        </div>
                    </summary>

                    <div class="mt-3 space-y-3 border-t border-gray-100 pt-3 dark:border-white/10">
                        @if (filled($check['note'] ?? null) && $summary)
                            <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                                {{ $check['note'] }}
                            </p>
                        @endif

                        @forelse ($sections as $section)
                            <div>
                                <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $section['title'] }}
                                </p>

                                @if (($section['format'] ?? 'table') === 'metrics')
                                    <dl class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        @foreach ($section['rows'] as $metricRow)
                                            @foreach ($metricRow as $label => $value)
                                                <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-white/10">
                                                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                                    <dd class="mt-0.5 text-xs font-medium text-gray-900 dark:text-white">{{ $value }}</dd>
                                                </div>
                                            @endforeach
                                        @endforeach
                                    </dl>
                                @else
                                    <div class="overflow-x-auto rounded-lg border border-gray-100 dark:border-white/10">
                                        <table class="w-full min-w-[32rem] text-start text-xs">
                                            <thead class="bg-gray-50/80 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                                <tr>
                                                    @foreach (array_keys($section['rows'][0] ?? []) as $column)
                                                        <th class="px-3 py-2 whitespace-nowrap">{{ Presenter::detailCellLabel($column) }}</th>
                                                    @endforeach
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                                @foreach ($section['rows'] as $detailRow)
                                                    <tr>
                                                        @foreach ($detailRow as $column => $value)
                                                            <td class="px-3 py-2 align-top text-gray-800 dark:text-gray-200">
                                                                {!! Presenter::detailCellValue($column, $value, $currency) !!}
                                                            </td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    @if ($section['truncated'])
                                        <p class="mt-1 text-[11px] text-amber-700 dark:text-amber-300">
                                            {{ __('Additional rows were truncated in this snapshot. Download JSON for the full payload.') }}
                                        </p>
                                    @endif
                                @endif
                            </div>
                        @empty
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('No additional detail rows for this check.') }}</p>
                        @endforelse
                    </div>
                </details>
            @endforeach
        </div>
    </section>

    @if (! empty($report['coverage_matrix']))
        <details class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/60">
            <summary class="cursor-pointer list-none px-4 py-3 sm:px-5 [&::-webkit-details-marker]:hidden">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Coverage matrix') }}</h4>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Which checks apply to each flow or posting area.') }}
                        </p>
                    </div>
                    <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-gray-400" />
                </div>
            </summary>
            <div class="border-t border-gray-100 px-4 py-3 dark:border-white/10 sm:px-5">
                <div class="space-y-3">
                    @foreach ($report['coverage_matrix'] as $matrixRow)
                        <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-white/10">
                            <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ $matrixRow['flow'] ?? '—' }}</p>
                            <ul class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($matrixRow['checks'] ?? [] as $matrixCheck)
                                    @php
                                        $matrixKey = (string) ($matrixCheck['key'] ?? '');
                                        $matrixSeverity = (string) ($matrixCheck['severity'] ?? 'unknown');
                                        $matrixStyle = Presenter::severityStyle($matrixSeverity);
                                        $matrixLabel = $checks[$matrixKey]['label'] ?? str($matrixKey)->replace('_', ' ')->headline();
                                    @endphp
                                    <li class="inline-flex max-w-full items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium {{ $matrixStyle['badge'] }}">
                                        <span class="truncate">{{ $matrixLabel }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        </details>
    @endif
</div>
