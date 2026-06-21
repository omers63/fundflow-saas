@php
    $currency = $d['currency'];
    $accentBar = ['teal' => 'bg-teal-500', 'indigo' => 'bg-indigo-500', 'violet' => 'bg-violet-500', 'rose' => 'bg-rose-500'];
@endphp

<x-loan-pipeline-stepper :steps="$d['steps']" />

@include('filament.tenant.widgets.partials.insights-head', [
    'hero' => $d['hero'],
    'kpis' => $d['kpis'],
])

<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
    @foreach ($d['progress'] as $key => $bar)
        <div
            class="rounded-xl border border-gray-200/80 bg-white px-3 py-2.5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="mb-1 flex justify-between text-[10px] font-medium text-gray-500">
                <span>{{ $bar['label'] }}</span>
                <span class="tabular-nums text-gray-900 dark:text-white">{{ $bar['percent'] }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                <div @class(['h-full rounded-full', 'bg-indigo-500' => $key === 'disburse', 'bg-teal-500' => $key === 'repay'])
                    style="width: {{ max(4, $bar['percent']) }}%"></div>
            </div>
        </div>
    @endforeach
</div>

@if ($d['next_due'])
    <div
        class="relative overflow-hidden rounded-xl border border-amber-200 bg-white px-3 py-2.5 shadow-sm dark:border-amber-500/25 dark:bg-slate-800">
        <div class="absolute inset-y-0 left-0 w-0.5 bg-amber-500"></div>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <x-heroicon-o-calendar-days class="h-4 w-4 text-amber-600" />
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-200">
                        {{ __('Next installment') }}</p>
                    <p class="text-sm font-bold text-gray-900 dark:text-white">
                        <x-member::amount :value="$d['next_due']['amount']" :currency="$currency" class="inline" />
                        · {{ $d['next_due']['date'] }}
                    </p>
                </div>
            </div>
            @if ($d['next_due']['is_overdue'])
                <span class="rounded bg-rose-600 px-2 py-0.5 text-[10px] font-bold text-white">{{ __('Overdue') }}</span>
            @endif
        </div>
    </div>
@endif

<div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
    @foreach ($d['relation_summaries'] as $card)
        @php $bar = $accentBar[$card['accent']] ?? 'bg-gray-400'; @endphp
        <div
            class="relative overflow-hidden rounded-xl border border-gray-200/80 bg-white px-3 py-2.5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="absolute inset-y-0 left-0 w-0.5 {{ $bar }}"></div>
            <div class="flex items-center gap-1.5 pl-1">
                <x-dynamic-component :component="$card['icon']" class="h-3.5 w-3.5 text-gray-400" />
                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ ui_label($card['label']) }}
                </p>
            </div>
            <p class="mt-1 pl-1 text-sm font-bold text-gray-900 dark:text-white">
                @if (($card['money_amount'] ?? null) !== null)
                    <x-member::amount :value="$card['money_amount']" :currency="$currency" :compact="($card['money_compact'] ?? false)" class="inline" />
                @else
                    <x-ff-money-text :text="$card['value']" :currency="$currency" class="inline" />
                @endif
            </p>
            @if (filled($card['hint'] ?? null))
                <p class="pl-1 text-[10px] text-gray-400">{{ $card['hint'] }}</p>
            @endif
        </div>
    @endforeach
</div>

@if ($d['guarantor'])
    <div
        class="rounded-xl border border-gray-200/80 bg-white px-3 py-2 text-[11px] shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <span class="font-semibold text-gray-700 dark:text-gray-200">{{ __('Guarantor') }}:</span>
        {{ $d['guarantor']['name'] }}
        @if ($d['guarantor']['released'])
            · <span class="text-emerald-600">{{ __('Released') }}</span>
        @elseif ($d['guarantor']['liability_transferred'])
            · <span class="text-rose-600">{{ __('Liability transferred') }}</span>
        @endif
    </div>
@endif