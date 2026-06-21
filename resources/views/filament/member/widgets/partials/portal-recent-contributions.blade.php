@props(['contributions', 'deposits'])

<div class="grid grid-cols-1 gap-3 md:grid-cols-2">
    <div
        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {{ __('Recent contributions') }}
            </h4>
        </div>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
            @forelse ($contributions as $row)
                <li class="flex items-center justify-between gap-2 px-3 py-2 text-xs">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ $row['period'] }}</p>
                        <p class="text-[10px] text-gray-400">
                            {{ $row['posted_at'] }}
                            @if ($row['late'] ?? false)
                                · <span class="text-rose-600 dark:text-rose-400">{{ __('Late') }}</span>
                            @endif
                        </p>
                    </div>
                    <x-ff-money-text :text="$row['amount']" @class([
                        'shrink-0 font-semibold tabular-nums',
                        'text-rose-600 dark:text-rose-400' => $row['late'] ?? false,
                        'text-emerald-600 dark:text-emerald-400' => ! ($row['late'] ?? false),
                    ]) />
                </li>
            @empty
                <li class="px-3 py-4 text-center text-[11px] text-gray-400">{{ __('No contributions yet') }}</li>
            @endforelse
        </ul>
    </div>

    <div
        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {{ __('Recent deposits') }}
            </h4>
        </div>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
            @forelse ($deposits as $deposit)
                <li class="flex items-center justify-between gap-2 px-3 py-2 text-xs">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ $deposit['date'] }}</p>
                        <p class="text-[10px] text-gray-400">{{ $deposit['status_label'] }}</p>
                    </div>
                    <x-ff-money-text :text="$deposit['amount']" class="shrink-0 font-semibold tabular-nums text-gray-900 dark:text-white" />
                </li>
            @empty
                <li class="px-3 py-4 text-center text-[11px] text-gray-400">{{ __('No deposits yet') }}</li>
            @endforelse
        </ul>
    </div>
</div>