@php
    use App\Filament\Support\MoneyDisplay;

    $d = $this->getData();
    $latest = $d['latest'] ?? null;
    $maxTrend = max(1, collect($d['trend'])->max('closing'));
    $sparkMax = max(1, max($d['sparkline']));
    $currency = $d['currency'];
    $accentBar = ['amber' => 'bg-amber-500', 'emerald' => 'bg-emerald-500', 'rose' => 'bg-rose-500', 'sky' => 'bg-sky-500', 'violet' => 'bg-violet-500', 'teal' => 'bg-teal-500'];
    $accentIcon = ['amber' => 'text-amber-500', 'emerald' => 'text-emerald-500', 'rose' => 'text-rose-500', 'sky' => 'text-sky-500', 'violet' => 'text-violet-500', 'teal' => 'text-teal-500'];
    $kpis = [
        ['label' => __('Statements'), 'value' => $d['total'], 'sub' => __('On file'), 'icon' => 'heroicon-o-document-chart-bar', 'accent' => 'sky', 'active' => true],
        ['label' => __('Latest'), 'value' => $latest ? $latest['period_label'] : '—', 'sub' => __('Period'), 'icon' => 'heroicon-o-calendar', 'accent' => 'violet', 'active' => $latest !== null],
        ['label' => __('Closing'), 'amount' => $latest['closing'] ?? null, 'precision' => 0, 'sub' => __('Balance'), 'icon' => 'heroicon-o-banknotes', 'accent' => 'emerald', 'active' => $latest !== null],
        ['label' => __('Contributions'), 'amount' => $latest['contributions'] ?? null, 'precision' => 0, 'sub' => __('Period'), 'icon' => 'heroicon-o-arrow-trending-up', 'accent' => 'teal', 'active' => true],
        ['label' => __('Repayments'), 'amount' => $latest['repayments'] ?? null, 'precision' => 0, 'sub' => __('Period'), 'icon' => 'heroicon-o-arrow-trending-down', 'accent' => 'amber', 'active' => true],
        ['label' => __('This year'), 'value' => $d['generated_this_year'], 'sub' => __('Generated'), 'icon' => 'heroicon-o-sparkles', 'accent' => 'rose', 'active' => $d['generated_this_year'] > 0],
    ];
@endphp

@if ($d !== [])
    <div class="ff-app-insights w-full max-w-none space-y-3 mb-1">
        <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
            <div @class([
                'ff-app-insights-hero overflow-hidden rounded-xl border px-3 py-2.5 shadow-sm lg:col-span-1',
                'border-indigo-200/80 bg-gradient-to-r from-indigo-50 to-violet-50/80 dark:border-indigo-500/30 dark:from-indigo-950/40 dark:to-violet-950/20' => $latest !== null,
                'border-gray-200/70 bg-gradient-to-r from-gray-50 to-slate-50/60 dark:border-gray-600/25 dark:from-gray-950/30 dark:to-slate-950/20' => $latest === null,
            ])>
                <div class="flex flex-col items-stretch gap-2">
                    @if ($latest)
                        <div class="flex min-w-0 items-start gap-2">
                            <x-heroicon-o-document-chart-bar
                                class="mt-0.5 h-4 w-4 shrink-0 text-indigo-600 dark:text-indigo-400" />
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-indigo-900 dark:text-indigo-100">
                                    {{ __('Latest statement: :period', ['period' => $latest['period_label']]) }}
                                </p>
                                <p class="mt-0.5 text-[11px] text-gray-600 dark:text-gray-400">
                                    <x-member::amount :value="$latest['closing']" :currency="$currency" :precision="0"
                                        class="inline" />
                                    @if ($latest['notified'])
                                        · <span class="text-emerald-600">{{ __('Delivered') }}</span>
                                    @else
                                        · <span class="text-amber-600">{{ __('New') }}</span>
                                    @endif
                                </p>
                            </div>
                        </div>
                        @if ($latest['pdf_url'])
                            <a href="{{ $latest['pdf_url'] }}"
                                class="self-start rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-500">
                                {{ __('PDF') }}
                            </a>
                        @endif
                    @else
                        <div class="flex items-start gap-2">
                            <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 shrink-0 text-gray-500" />
                            <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ __('No statements yet') }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 lg:col-span-2">
                <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-gray-700 sm:grid-cols-6">
                    @foreach ($kpis as $i => $card)
                        @php
                            $barClass = $accentBar[$card['accent']] ?? 'bg-gray-400';
                            $iconClass = $accentIcon[$card['accent']] ?? 'text-gray-400';
                            $barOpacity = $card['active'] ? 'opacity-100' : 'opacity-25';
                        @endphp
                        @php
                            $labelText = ui_label($card['label']);
                            $subText = ui_label($card['sub']);
                            $valueText = isset($card['amount'])
                                ? (MoneyDisplay::format($card['amount'], $currency, precision: $card['precision'] ?? 2) ?? '—')
                                : (string) ($card['value'] ?? '—');
                        @endphp
                        <div class="ff-app-insights-kpi ff-member-stat-card relative min-w-0 px-2.5 py-2 transition"
                            data-accent="{{ $card['accent'] }}"
                            style="animation: ff-stat-in 0.35s ease-out {{ 0.02 + ($i * 0.03) }}s both">
                            <div @class(['absolute inset-y-0 left-0 w-0.5', $barClass, $barOpacity])></div>
                            <div class="flex items-center justify-between gap-1 pl-1">
                                <x-dynamic-component :component="$card['icon']" @class(['h-3.5 w-3.5', $iconClass]) />
                            </div>
                            <x-ff-stat-line :text="$labelText"
                                class="mt-0.5 truncate pl-1 text-[10px] font-medium uppercase tracking-wide text-gray-500" />
                            @if (isset($card['amount']))
                                <x-ff-stat-line :amount="$card['amount']" :currency="$currency" :precision="$card['precision'] ?? 2"
                                    class="truncate pl-1 text-lg font-bold tabular-nums leading-tight text-gray-900 dark:text-white" />
                            @else
                                <x-ff-stat-line :text="$valueText"
                                    class="truncate pl-1 text-lg font-bold tabular-nums leading-tight text-gray-900 dark:text-white" />
                            @endif
                            <x-ff-stat-line :text="$subText" class="truncate pl-1 text-[10px] text-gray-400" />
                        </div>
                    @endforeach
                </div>
                @if (collect($d['sparkline'])->sum() > 0)
                    <div class="flex h-5 items-end gap-px border-t border-gray-100 px-2 py-1 dark:border-gray-700">
                        @foreach ($d['sparkline'] as $point)
                            @php $h = max(20, (int) round(($point / $sparkMax) * 100)); @endphp
                            <div class="flex-1 rounded-sm bg-violet-400/70 dark:bg-violet-500/60" style="height: {{ $h }}%"></div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        @if (count($d['trend']) > 0)
            <div
                class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-chart-bar class="h-4 w-4 text-indigo-500" />
                        <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                            {{ __('Statement history') }}
                        </h4>
                    </div>
                    <div class="flex flex-wrap gap-3 text-[10px] text-gray-500">
                        <span class="flex items-center gap-1"><span
                                class="h-2 w-2 rounded-sm bg-emerald-500"></span>{{ __('Contributions') }}</span>
                        <span class="flex items-center gap-1"><span
                                class="h-2 w-2 rounded-sm bg-rose-500"></span>{{ __('Repayments') }}</span>
                    </div>
                </div>
                <div class="px-3 py-3">
                    <div class="flex h-20 items-end gap-1.5 sm:gap-2">
                        @foreach ($d['trend'] as $month)
                            @php
                                $stackTotal = max(1, $month['contributions'] + $month['repayments']);
                                $contribH = round(($month['contributions'] / $stackTotal) * 100);
                                $repayH = round(($month['repayments'] / $stackTotal) * 100);
                                $barH = max(12, (int) round(($month['closing'] / $maxTrend) * 100));
                            @endphp
                            <div class="flex flex-1 flex-col items-center gap-0.5">
                                <span class="text-[10px] font-semibold tabular-nums text-gray-500">
                                    <x-member::amount :value="$month['closing']" :currency="$currency" :precision="0"
                                        class="inline text-[10px]" />
                                </span>
                                <div class="flex w-full max-w-[2.25rem] flex-col justify-end overflow-hidden rounded-t-md ring-1 ring-gray-200/60 dark:ring-gray-600"
                                    style="height: {{ $barH }}%">
                                    @if ($month['contributions'] > 0)
                                        <div class="w-full bg-emerald-500" style="height: {{ max(3, $contribH) }}%"></div>
                                    @endif
                                    @if ($month['repayments'] > 0)
                                        <div class="w-full bg-rose-500" style="height: {{ max(3, $repayH) }}%"></div>
                                    @endif
                                    @if ($month['contributions'] + $month['repayments'] <= 0)
                                        <div class="h-0.5 w-full bg-gray-200 dark:bg-gray-600"></div>
                                    @endif
                                </div>
                                <span class="text-[10px] text-gray-400">{{ $month['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
@endif