@php
use App\Support\Reconciliation\ReconciliationSnapshotPresenter as Presenter;

$currency ??= Presenter::currency();
@endphp

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
@elseif (($section['format'] ?? 'table') === 'hints')
    <ul class="list-disc space-y-1 ps-5 text-xs text-gray-600 dark:text-gray-300">
        @foreach ($section['rows'] as $hintRow)
            <li>{{ $hintRow['hint'] ?? '' }}</li>
        @endforeach
    </ul>
@else
    @php
        $tableAlign = $section['table_align'] ?? 'start';
        $cellAlignClass = $tableAlign === 'center' ? 'text-center' : 'text-start';
    @endphp
    <div class="overflow-x-auto rounded-lg border border-gray-100 dark:border-white/10">
        <table @class([
            'w-full min-w-[32rem] text-xs',
            'text-center' => $tableAlign === 'center',
            'text-start' => $tableAlign !== 'center',
        ])>
            <thead class="bg-gray-50/80 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr>
                    @foreach (array_keys($section['rows'][0] ?? []) as $column)
                        <th class="px-3 py-2 whitespace-nowrap {{ $cellAlignClass }}">{{ Presenter::detailCellLabel($column) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($section['rows'] as $detailRow)
                    <tr>
                        @foreach ($detailRow as $column => $value)
                            <td class="px-3 py-2 align-top text-gray-800 dark:text-gray-200 {{ $cellAlignClass }}">
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
