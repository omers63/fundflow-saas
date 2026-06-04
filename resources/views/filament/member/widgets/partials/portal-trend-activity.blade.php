@props(['trend', 'recentActivity', 'quickLinks'])

<div class="grid grid-cols-1 gap-3 md:grid-cols-2">
    @include('filament.partials.insights.six-month-dual-progress-panel', [
        'title' => __('6-month contributions'),
        'trend' => $trend,
        'primaryLabel' => __('Posted'),
        'secondaryLabel' => __('Amount'),
    ])

    <div
        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-clock class="h-4 w-4 text-sky-500" />
                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    {{ __('Recent activity') }}
                </h4>
            </div>
            <div class="flex gap-2">
                @foreach ($quickLinks as $link)
                    <a href="{{ $link['url'] }}" title="{{ $link['label'] }}"
                        class="text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400">
                        <x-dynamic-component :component="$link['icon']" class="h-3.5 w-3.5" />
                    </a>
                @endforeach
            </div>
        </div>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
            @forelse ($recentActivity as $tx)
                <li class="flex items-start justify-between gap-2 px-3 py-2 text-xs">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ $tx['description'] }}</p>
                        <p class="text-[10px] text-gray-400">{{ $tx['transacted_at'] }}</p>
                    </div>
                    <span @class(['shrink-0 font-semibold tabular-nums', $tx['signed_class']])>
                        {{ $tx['amount'] }}
                    </span>
                </li>
            @empty
                <li class="px-3 py-4 text-center text-[11px] text-gray-400 dark:text-gray-500">
                    {{ __('No ledger activity yet') }}
                </li>
            @endforelse
        </ul>
    </div>
</div>
