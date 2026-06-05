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