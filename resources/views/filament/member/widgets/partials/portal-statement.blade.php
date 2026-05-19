@props(['statement'])

@if ($statement)
    <a href="{{ $statement['url'] }}"
        class="flex items-center justify-between gap-3 overflow-hidden rounded-xl border border-indigo-200/80 bg-gradient-to-r from-indigo-50 to-violet-50/60 px-3 py-2.5 shadow-sm transition hover:shadow-md dark:border-indigo-500/25 dark:from-indigo-950/30 dark:to-violet-950/20">
        <div class="flex items-center gap-2">
            <x-heroicon-o-document-chart-bar class="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-wider text-indigo-800/80 dark:text-indigo-200">
                    {{ __('Latest statement') }}
                </p>
                <p class="text-sm font-bold text-gray-900 dark:text-white">{{ $statement['period'] }}</p>
            </div>
        </div>
        <span class="text-[10px] font-medium text-indigo-700 dark:text-indigo-300">{{ __('Download') }} →</span>
    </a>
@endif