@props(['title' => null])

<details
    class="group overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm open:pb-3 dark:border-gray-700 dark:bg-gray-800">
    <summary
        class="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-gray-500 marker:content-none dark:text-gray-400 [&::-webkit-details-marker]:hidden">
        <span class="flex items-center gap-1.5">
            <x-heroicon-o-chart-bar class="h-4 w-4 text-violet-500" />
            {{ $title ?? __('Forecast & details') }}
        </span>
        <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 transition group-open:rotate-180" />
    </summary>
    <div class="space-y-3 border-t border-gray-100 px-3 pt-3 dark:border-gray-700">
        {{ $slot }}
    </div>
</details>
