@props([
    'steps' => [],
])

<nav aria-label="{{ __('Member lifecycle') }}" class="w-full">
    <ol class="m-0 flex list-none flex-row items-stretch gap-1 p-0 sm:gap-1.5">
        @foreach ($steps as $index => $step)
            @php
                $number = $index + 1;
                $state = $step['state'] ?? 'upcoming';
                $isComplete = $state === 'complete';
                $isCurrent = $state === 'current';
                $isWarning = $state === 'warning';
            @endphp
            <li
                @class([
                    'flex min-w-0 flex-1 flex-col items-center rounded-lg border px-1 py-1.5 text-center transition-all sm:px-1.5 sm:py-2',
                    'border-rose-500 bg-rose-50/80 ring-1 ring-rose-500/25 dark:bg-rose-500/10' => $isWarning,
                    'border-primary-500 bg-primary-50/80 ring-1 ring-primary-500/25 dark:bg-primary-500/10' => $isCurrent && ! $isWarning,
                    'border-emerald-200 bg-emerald-50/40 dark:border-emerald-500/30 dark:bg-emerald-500/10' => $isComplete && ! $isCurrent && ! $isWarning,
                    'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900/40' => ! $isCurrent && ! $isComplete && ! $isWarning,
                ])
            >
                <span
                    @class([
                        'mb-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold sm:mb-1 sm:h-7 sm:w-7 sm:text-xs',
                        'bg-rose-600 text-white' => $isWarning,
                        'bg-primary-600 text-white' => $isCurrent && ! $isWarning,
                        'bg-emerald-500 text-white' => $isComplete,
                        'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => ! $isCurrent && ! $isComplete && ! $isWarning,
                    ])
                >
                    @if ($isComplete)
                        <svg class="h-3 w-3 sm:h-3.5 sm:w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    @else
                        {{ $number }}
                    @endif
                </span>

                <span @class([
                    'w-full truncate px-0.5 text-[10px] font-semibold leading-tight sm:text-xs',
                    'text-rose-800 dark:text-rose-300' => $isWarning,
                    'text-primary-800 dark:text-primary-300' => $isCurrent && ! $isWarning,
                    'text-gray-900 dark:text-white' => ! $isCurrent && ! $isWarning,
                ])>
                    {{ $step['label'] }}
                </span>
            </li>
        @endforeach
    </ol>
</nav>
