@php
    use App\Filament\Support\MoneyDisplay;

    $poolLabel = $diagnostics['pool_label'] ?? __('Member cash');
@endphp

<div class="ff-member-drift-diagnostics space-y-4 text-sm">
    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-gray-200 bg-gray-50/80 px-3 py-2 dark:border-white/10 dark:bg-white/5">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Expected :pool', ['pool' => $poolLabel]) }}
            </p>
            <p class="mt-1 font-semibold tabular-nums text-gray-900 dark:text-white">
                {{ MoneyDisplay::format((float) $diagnostics['expected']) ?? '—' }}
            </p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50/80 px-3 py-2 dark:border-white/10 dark:bg-white/5">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Actual balance') }}
            </p>
            <p class="mt-1 font-semibold tabular-nums text-gray-900 dark:text-white">
                {{ MoneyDisplay::format((float) $diagnostics['actual']) ?? '—' }}
            </p>
        </div>
        <div class="rounded-lg border border-amber-200 bg-amber-50/80 px-3 py-2 dark:border-amber-500/30 dark:bg-amber-950/20">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-800 dark:text-amber-200">
                {{ __('Drift') }}
            </p>
            <p class="mt-1 font-semibold tabular-nums text-amber-900 dark:text-amber-100">
                {{ MoneyDisplay::format((float) $diagnostics['drift']) ?? '—' }}
            </p>
        </div>
    </div>

    <div>
        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('Expected balance formula (§5.13)') }}
        </p>
        @if (($diagnostics['formula_lines'] ?? []) === [])
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('No non-zero components.') }}</p>
        @else
            <div class="mt-2 overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="min-w-full text-xs">
                    <thead class="bg-gray-50 text-left text-[10px] uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">{{ __('Component') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('Sign') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($diagnostics['formula_lines'] as $line)
                            <tr>
                                <td class="px-3 py-2 text-gray-800 dark:text-gray-200">{{ $line['label'] }}</td>
                                <td class="px-3 py-2 text-end font-mono text-gray-500">{{ $line['sign'] }}</td>
                                <td class="px-3 py-2 text-end font-semibold tabular-nums text-gray-900 dark:text-white">
                                    {{ MoneyDisplay::format((float) $line['amount']) ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                        <tr class="bg-gray-50/80 font-semibold dark:bg-white/5">
                            <td class="px-3 py-2 text-gray-900 dark:text-white">{{ __('= Expected') }}</td>
                            <td class="px-3 py-2 text-end font-mono">=</td>
                            <td class="px-3 py-2 text-end tabular-nums text-gray-900 dark:text-white">
                                {{ MoneyDisplay::format((float) $diagnostics['expected']) ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if (($diagnostics['uncounted_flows'] ?? []) !== [])
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Ledger legs not in the formula') }}
            </p>
            <div class="mt-2 space-y-2">
                @foreach ($diagnostics['uncounted_flows'] as $flow)
                    <div class="rounded-lg border border-violet-200 bg-violet-50/60 px-3 py-2 dark:border-violet-500/30 dark:bg-violet-950/20">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium text-violet-900 dark:text-violet-100">{{ $flow['label'] }}</span>
                            <span class="font-semibold tabular-nums text-violet-900 dark:text-violet-100">
                                {{ $flow['sign'] }} {{ MoneyDisplay::format((float) $flow['amount']) ?? '—' }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-violet-800/90 dark:text-violet-200/90">{{ $flow['detail'] }}</p>
                    </div>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                {{ __('Adjusted expected (formula + uncounted legs): :amount', [
                    'amount' => MoneyDisplay::format((float) $diagnostics['adjusted_expected']) ?? '—',
                ]) }}
                · {{ __('Adjusted drift: :amount', [
                    'amount' => MoneyDisplay::format((float) $diagnostics['adjusted_drift']) ?? '—',
                ]) }}
            </p>
        </div>
    @endif

    @if (($diagnostics['mismatch_transactions'] ?? []) !== [])
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Sample transactions driving the mismatch') }}
            </p>
            <div class="mt-2 overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="min-w-full text-xs">
                    <thead class="bg-gray-50 text-left text-[10px] uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">#</th>
                            <th class="px-3 py-2">{{ __('Date') }}</th>
                            <th class="px-3 py-2">{{ __('Type') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('Amount') }}</th>
                            <th class="px-3 py-2">{{ __('Category') }}</th>
                            <th class="px-3 py-2">{{ __('Description') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($diagnostics['mismatch_transactions'] as $txn)
                            <tr>
                                <td class="px-3 py-2 tabular-nums text-gray-500">{{ $txn['id'] }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $txn['date'] }}</td>
                                <td class="px-3 py-2 capitalize text-gray-700 dark:text-gray-300">{{ $txn['type'] }}</td>
                                <td class="px-3 py-2 text-end font-semibold tabular-nums text-gray-900 dark:text-white">
                                    {{ MoneyDisplay::format((float) $txn['amount']) ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ $txn['category'] }}</td>
                                <td class="max-w-xs truncate px-3 py-2 text-gray-600 dark:text-gray-400" title="{{ $txn['description'] }}">
                                    {{ $txn['description'] }}
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('Showing up to 20 rows.') }}</p>
        </div>
    @endif

    @php($correction = $diagnostics['suggested_correction'] ?? [])
    <div @class([
        'rounded-lg border px-3 py-3',
        'border-emerald-200 bg-emerald-50/70 dark:border-emerald-500/30 dark:bg-emerald-950/20' => ($correction['action'] ?? '') === 'resolve',
        'border-sky-200 bg-sky-50/70 dark:border-sky-500/30 dark:bg-sky-950/20' => ($correction['action'] ?? '') === 'post_correction',
        'border-gray-200 bg-gray-50/70 dark:border-white/10 dark:bg-white/5' => ($correction['action'] ?? '') === 'none',
    ])>
        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
            {{ __('Suggested correction') }}
        </p>
        <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ $correction['summary'] ?? '—' }}</p>
        @if (($correction['action'] ?? '') === 'post_correction')
            <p class="mt-2 text-xs font-semibold text-sky-800 dark:text-sky-200">
                {{ __('Use “Post cash correction” with direction :direction and amount :amount.', [
                    'direction' => $correction['direction'] === 'credit' ? __('Credit member cash') : __('Debit member cash'),
                    'amount' => MoneyDisplay::format((float) ($correction['amount'] ?? 0)) ?? '—',
                ]) }}
            </p>
        @endif
        @if (filled($correction['caution'] ?? null))
            <p class="mt-2 text-xs font-semibold text-amber-800 dark:text-amber-200">{{ $correction['caution'] }}</p>
        @endif
    </div>
</div>
