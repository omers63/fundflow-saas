@php
    $summary = $summary ?? [];
    $currency = $summary['currency'] ?? null;
    $memberMeta = $summary['member'] ?? [];
    $balances = $summary['balances'] ?? [];
    $contributions = $summary['contributions'] ?? [];
    $cycle = $summary['cycle'] ?? [];
    $arrears = $summary['arrears'] ?? [];
    $loan = $summary['loan'] ?? null;
    $household = $summary['household'] ?? [];
    $links = $summary['links'] ?? [];
    $cycleTone = $cycle['tone'] ?? 'gray';
    $hasStatusChips = filled($cycle['label'] ?? null)
        || ($arrears['visible'] ?? false)
        || filled($cycle['url'] ?? null);
    $hasHousehold = filled($household['parent_name'] ?? null)
        || ($household['dependents_count'] ?? 0) > 0;
    $statusTone = match ($memberMeta['status'] ?? null) {
        'active' => 'success',
        'inactive' => 'warning',
        'withdrawn' => 'danger',
        default => 'gray',
    };
@endphp

<div class="ff-member-workspace-summary mb-3 w-full max-w-none space-y-3">
    <section
        class="overflow-hidden rounded-xl border border-gray-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200/80 px-3 py-2.5 dark:border-white/10">
            <div class="min-w-0">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    {{ __('Overview') }}
                </h2>
                @if (filled($memberMeta['member_number'] ?? null))
                    <p class="truncate text-[11px] text-gray-400 dark:text-gray-500">
                        {{ $memberMeta['member_number'] }}
                    </p>
                @endif
            </div>

            @if (filled($memberMeta['status_label'] ?? null))
                <span @class([
                    'inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold',
                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' => $statusTone === 'success',
                    'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' => $statusTone === 'warning',
                    'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300' => $statusTone === 'danger',
                    'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $statusTone === 'gray',
                ])>
                    {{ $memberMeta['status_label'] }}
                </span>
            @endif
        </div>

        <div class="space-y-3 p-3">
            <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                <a href="{{ $balances['cash']['url'] ?? '#' }}" @class([
                    'ff-member-workspace-balance block min-w-0 overflow-hidden rounded-lg border border-gray-200/90 px-3 py-2.5 transition hover:bg-gray-50 dark:border-white/10 dark:hover:bg-gray-800/80',
                    'pointer-events-none opacity-70' => empty($balances['cash']['url']),
                ])>
                    <p class="truncate text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('Cash') }}
                    </p>
                    <x-ff-stat-line
                        :amount="$balances['cash']['amount'] ?? 0"
                        :currency="$currency"
                        @class([
                            'mt-0.5 text-lg font-extrabold tabular-nums tracking-tight sm:text-xl',
                            ($balances['cash']['negative'] ?? false)
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-emerald-600 dark:text-emerald-400',
                        ])
                    />
                </a>

                <a href="{{ $balances['fund']['url'] ?? '#' }}" @class([
                    'ff-member-workspace-balance block min-w-0 overflow-hidden rounded-lg border border-gray-200/90 px-3 py-2.5 transition hover:bg-gray-50 dark:border-white/10 dark:hover:bg-gray-800/80',
                    'pointer-events-none opacity-70' => empty($balances['fund']['url']),
                ])>
                    <p class="truncate text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('Fund') }}
                    </p>
                    <x-ff-stat-line
                        :amount="$balances['fund']['amount'] ?? 0"
                        :currency="$currency"
                        @class([
                            'mt-0.5 text-lg font-extrabold tabular-nums tracking-tight sm:text-xl',
                            ($balances['fund']['negative'] ?? false)
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-indigo-600 dark:text-indigo-400',
                        ])
                    />
                </a>

                <div class="min-w-0 overflow-hidden rounded-lg border border-gray-200/90 px-3 py-2.5 dark:border-white/10">
                    <p class="truncate text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('Monthly') }}
                    </p>
                    <p class="mt-0.5 truncate text-lg font-extrabold tabular-nums tracking-tight text-gray-900 sm:text-xl dark:text-white"
                        title="{{ $summary['monthly_formatted'] ?? '—' }}">
                        {{ $summary['monthly_formatted'] ?? '—' }}
                    </p>
                </div>

                <a href="{{ $links['contributions'] ?? '#' }}" @class([
                    'block min-w-0 overflow-hidden rounded-lg border border-gray-200/90 px-3 py-2.5 transition hover:bg-gray-50 dark:border-white/10 dark:hover:bg-gray-800/80',
                    'pointer-events-none opacity-70' => empty($links['contributions']),
                ])>
                    <p class="truncate text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('Lifetime contributions') }}
                    </p>
                    <p class="mt-0.5 truncate text-lg font-extrabold tabular-nums tracking-tight text-sky-700 sm:text-xl dark:text-sky-300"
                        title="{{ $contributions['posted_total_formatted'] ?? '—' }}">
                        {{ $contributions['posted_total_formatted'] ?? '—' }}
                    </p>
                    <p class="mt-0.5 truncate text-[10px] text-gray-400 dark:text-gray-500">
                        {{ $contributions['hint'] ?? '' }}
                    </p>
                </a>
            </div>

            @if ($hasStatusChips)
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    @if (filled($cycle['label'] ?? null))
                        <span @class([
                            'inline-flex max-w-full items-center rounded-full px-2.5 py-1 text-[11px] font-semibold',
                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' => in_array($cycleTone, ['success'], true),
                            'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' => $cycleTone === 'warning',
                            'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300' => $cycleTone === 'violet',
                            'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $cycleTone === 'gray',
                        ])>
                            <span class="truncate">{{ $cycle['label'] }}</span>
                        </span>
                    @endif

                    @if ($arrears['visible'] ?? false)
                        <a href="{{ $arrears['cta_url'] ?? '#' }}"
                            class="ff-member-detail-chip ff-member-detail-chip--danger inline-flex max-w-full min-w-0 items-center gap-1.5">
                            <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0" />
                            <span class="truncate">{{ $arrears['cta_label'] ?? __('Arrears') }}</span>
                        </a>
                    @endif

                    @if (filled($cycle['url'] ?? null))
                        <a href="{{ $cycle['url'] }}"
                            class="text-[11px] font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                            {{ __('Open cycle') }}
                        </a>
                    @endif
                </div>
            @endif

            @if ($loan !== null)
                <div class="rounded-lg border border-gray-200/80 bg-gray-50/80 px-3 py-2.5 dark:border-white/10 dark:bg-gray-800/40">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold text-gray-700 dark:text-gray-200">
                                {{ __('Active loan') }}
                                <span class="font-normal text-gray-500 dark:text-gray-400">
                                    #{{ $loan['id'] }} · {{ $loan['status_label'] ?? '' }}
                                </span>
                            </p>
                            <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                                {{ __('Outstanding') }}:
                                <span class="font-semibold tabular-nums text-gray-900 dark:text-white">
                                    {{ $loan['outstanding_formatted'] ?? '—' }}
                                </span>
                                · {{ (int) ($loan['installments_paid'] ?? 0) }}/{{ (int) ($loan['installments_total'] ?? 0) }}
                                ({{ (int) ($loan['repay_percent'] ?? 0) }}%)
                            </p>
                        </div>
                        @if (filled($loan['url'] ?? null))
                            <a href="{{ $loan['url'] }}"
                                class="shrink-0 text-[11px] font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                {{ __('View loans') }}
                            </a>
                        @endif
                    </div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div
                            class="h-full rounded-full bg-violet-500 transition-all dark:bg-violet-400"
                            style="width: {{ min(100, max(0, (int) ($loan['repay_percent'] ?? 0))) }}%"
                        ></div>
                    </div>
                </div>
            @endif

            @if (filled($links['ledger'] ?? null) || filled($links['contributions'] ?? null) || filled($links['loans'] ?? null))
                <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-[11px]">
                    <span class="font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                        {{ __('Quick links') }}
                    </span>
                    @if (filled($links['ledger'] ?? null))
                        <a href="{{ $links['ledger'] }}"
                            class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                            {{ __('Ledger') }}
                        </a>
                    @endif
                    @if (filled($links['contributions'] ?? null))
                        <a href="{{ $links['contributions'] }}"
                            class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                            {{ __('Contributions') }}
                        </a>
                    @endif
                    @if (filled($links['loans'] ?? null))
                        <a href="{{ $links['loans'] }}"
                            class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                            {{ __('Loans') }}
                        </a>
                    @endif
                </div>
            @endif
        </div>

        @if ($hasHousehold)
            <div class="border-t border-gray-200/80 px-3 py-2.5 dark:border-white/10">
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
            </div>
        @endif
    </section>
</div>
