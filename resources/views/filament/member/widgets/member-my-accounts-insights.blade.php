@php
    $d = $this->getData();
@endphp

@if (empty($d))
    <div
        class="rounded-xl border border-dashed border-gray-200 px-4 py-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {{ __('Loading account insights…') }}
    </div>
@else
    <div class="ff-app-insights ff-member-accounts-insights w-full max-w-none space-y-2.5 mb-1">
        <div class="grid grid-cols-1 gap-2.5 lg:grid-cols-3">
            @include('filament.member.widgets.partials.insights-hero', ['hero' => $d['hero']])

            <div class="grid grid-cols-2 gap-2 lg:col-span-2">
                <a href="{{ $d['accounts']['cash']['url'] }}"
                    @class([
                        'block overflow-hidden rounded-xl border px-3 py-2 shadow-sm transition hover:shadow-md',
                        'border-sky-200/80 bg-gradient-to-br from-sky-50 to-cyan-50/60 dark:border-sky-500/25 dark:from-sky-950/30 dark:to-cyan-950/20' => ! ($d['cash_low'] ?? false),
                        'border-amber-200/80 bg-gradient-to-br from-amber-50 to-orange-50/60 dark:border-amber-500/25 dark:from-amber-950/30 dark:to-orange-950/20' => $d['cash_low'] ?? false,
                    ])>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ $d['accounts']['cash']['label'] }}</p>
                    <p @class([
                        'text-lg font-bold tabular-nums leading-tight',
                        ($d['cash_low'] ?? false) ? 'text-amber-700 dark:text-amber-300' : 'text-sky-700 dark:text-sky-300',
                    ])>{{ $d['accounts']['cash']['balance'] }}</p>
                </a>
                <a href="{{ $d['accounts']['fund']['url'] }}"
                    @class([
                        'block overflow-hidden rounded-xl border px-3 py-2 shadow-sm transition hover:shadow-md',
                        'border-indigo-200/80 bg-gradient-to-br from-indigo-50 to-violet-50/60 dark:border-indigo-500/25 dark:from-indigo-950/30 dark:to-violet-950/20' => ! ($d['fund_negative'] ?? false),
                        'border-rose-200/80 bg-gradient-to-br from-rose-50 to-orange-50/60 dark:border-rose-500/25 dark:from-rose-950/30 dark:to-orange-950/20' => $d['fund_negative'] ?? false,
                    ])>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ $d['accounts']['fund']['label'] }}</p>
                    <p @class([
                        'text-lg font-bold tabular-nums leading-tight',
                        ($d['fund_negative'] ?? false) ? 'text-rose-700 dark:text-rose-300' : 'text-indigo-700 dark:text-indigo-300',
                    ])>{{ $d['accounts']['fund']['balance'] }}</p>
                </a>
            </div>
        </div>

        @include('filament.member.widgets.partials.insights-kpi-strip', [
            'kpis' => $d['kpis'],
            'sparkline' => $d['sparkline'],
            'sparklineMax' => $d['sparkline_max'],
        ])

        <div class="grid grid-cols-1 gap-2.5 md:grid-cols-12">
            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 md:col-span-5">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-1.5 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-clock class="h-3.5 w-3.5 text-indigo-500" />
                        <h4 class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('Recent activity') }}</h4>
                    </div>
                    <span class="text-[10px] text-gray-400">{{ __('Across accounts') }}</span>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($d['recent'] as $tx)
                        <li class="flex items-start justify-between gap-2 px-3 py-1.5 text-xs">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-800 dark:text-gray-200">{{ $tx['description'] }}</p>
                                <p class="text-[10px] text-gray-400">
                                    {{ $tx['transacted_at'] }}
                                    @if (filled($tx['account_type'] ?? null))
                                        · {{ ucfirst($tx['account_type']) }}
                                    @endif
                                </p>
                            </div>
                            <span @class(['shrink-0 font-semibold tabular-nums', $tx['signed_class']])>
                                {{ $tx['prefix'] }}{{ $tx['amount'] }}
                            </span>
                        </li>
                    @empty
                        <li class="px-3 py-4 text-center text-[11px] text-gray-400">{{ __('No transactions yet') }}</li>
                    @endforelse
                </ul>
            </div>

            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 md:col-span-7">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-1.5 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-chart-bar class="h-3.5 w-3.5 text-emerald-500" />
                        <h4 class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('6-month ledger volume') }}</h4>
                    </div>
                    <div class="flex gap-2 text-[9px] text-gray-400">
                        <span class="flex items-center gap-0.5"><span class="h-1.5 w-1.5 rounded-sm bg-emerald-500"></span>{{ __('In') }}</span>
                        <span class="flex items-center gap-0.5"><span class="h-1.5 w-1.5 rounded-sm bg-rose-400"></span>{{ __('Out') }}</span>
                    </div>
                </div>
                <div class="px-2.5 py-2">
                    <div class="flex h-16 items-end gap-1">
                        @foreach ($d['trend'] as $month)
                            @php
                                $barH = max(10, (int) round(($month['total'] / $d['trend_max']) * 100));
                                $creditH = $month['total'] > 0 ? (int) round(($month['credits'] / $month['total']) * 100) : 0;
                            @endphp
                            <div class="flex flex-1 flex-col items-center gap-0.5">
                                <span class="text-[9px] font-semibold tabular-nums text-gray-400">
                                    {{ $month['total'] > 0 ? \App\Support\Insights\InsightFormatter::compactAmount($month['total']) : '·' }}
                                </span>
                                <div class="flex w-full max-w-[2rem] flex-col justify-end overflow-hidden rounded-t-md ring-1 ring-gray-200/50 dark:ring-gray-600"
                                    style="height: {{ $barH }}%">
                                    @if ($month['credits'] > 0)
                                        <div class="w-full bg-emerald-500" style="height: {{ max(3, $creditH) }}%"></div>
                                    @endif
                                    @if ($month['debits'] > 0)
                                        <div class="w-full bg-rose-400" style="height: {{ max(3, 100 - $creditH) }}%"></div>
                                    @endif
                                </div>
                                <span class="text-[9px] text-gray-400">{{ $month['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
