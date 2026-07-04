@php
    $currency = $d['currency'];
@endphp

<div class="grid grid-cols-1 gap-3 md:grid-cols-3">
    @include('filament.member.widgets.partials.insights-hero', ['hero' => $d['hero']])
    <div
        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 md:col-span-2">
        <div class="grid grid-cols-2 divide-x divide-gray-100 dark:divide-gray-700 sm:grid-cols-4">
            @foreach ($d['kpis'] as $i => $card)
                @php
                    $accent = $card['accent'] ?? ['sky', 'violet', 'emerald', 'amber'][$i % 4];
                @endphp
                <div class="ff-app-insights-kpi ff-member-stat-card min-w-0 px-2.5 py-2" data-accent="{{ $accent }}">
                    <x-ff-stat-line :text="ui_label($card['label'])"
                        class="truncate text-[10px] font-medium uppercase tracking-wide text-gray-500" />
                    <x-ff-stat-line :text="(string) $card['value']"
                        class="truncate text-lg font-bold tabular-nums text-gray-900 dark:text-white" />
                    <x-ff-stat-line :text="ui_label($card['sub'])" class="truncate text-[10px] text-gray-400" />
                </div>
            @endforeach
        </div>
    </div>
</div>

@if (!empty($d['forecast']))
    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
        <div class="rounded-xl border border-violet-200/80 bg-violet-50/60 px-3 py-3 shadow-sm dark:border-violet-800/40 dark:bg-violet-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">{{ __('Next EMI') }}</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $d['forecast']['next_emi_date'] ?? __('No active loan') }}</p>
            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                @if (($d['forecast']['next_emi_amount'] ?? 0) > 0)
                    <x-member::amount :value="$d['forecast']['next_emi_amount']" :currency="$currency" class="inline" />
                @else
                    {{ __('No amount due') }}
                @endif
            </p>
        </div>
        <div class="rounded-xl border border-sky-200/80 bg-sky-50/60 px-3 py-3 shadow-sm dark:border-sky-800/40 dark:bg-sky-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-300">{{ __('Next 30 days') }}</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ trans_choice(':count EMI due|:count EMIs due', $d['forecast']['next_30_days_count'], ['count' => $d['forecast']['next_30_days_count']]) }}</p>
            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300"><x-member::amount :value="$d['forecast']['next_30_days_amount']" :currency="$currency" class="inline" /></p>
        </div>
        <div class="rounded-xl border border-emerald-200/80 bg-emerald-50/60 px-3 py-3 shadow-sm dark:border-emerald-800/40 dark:bg-emerald-950/20">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-300">{{ __('Cash coverage') }}</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ ($d['forecast']['cash_covers_next_emi'] ?? false) ? __('Covered') : __('Top-up needed') }}</p>
            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                {{ ($d['forecast']['cash_covers_next_emi'] ?? false) ? __('Current cash covers your next EMI.') : __('Gap: :amount', ['amount' => \App\Support\Insights\InsightFormatter::money($d['forecast']['cash_gap'])]) }}
            </p>
        </div>
    </div>
@endif