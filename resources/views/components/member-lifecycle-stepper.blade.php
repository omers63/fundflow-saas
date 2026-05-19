@props([
    'steps' => [],
    'variant' => 'compact',
])

@if ($variant === 'journey')
    @php
        $stepMeta = [
            'joined' => [
                'icon' => 'heroicon-o-user-plus',
                'hint_complete' => __('Enrolled'),
                'hint_current' => __('Complete your profile'),
                'hint_upcoming' => __('Upcoming'),
            ],
            'active' => [
                'icon' => 'heroicon-o-check-badge',
                'hint_complete' => __('In good standing'),
                'hint_current' => __('Membership active'),
                'hint_warning' => __('Needs attention'),
                'hint_upcoming' => __('Pending activation'),
            ],
            'cycle' => [
                'icon' => 'heroicon-o-arrow-path',
                'hint_complete' => __('Posted this cycle'),
                'hint_current' => __('Due this cycle'),
                'hint_upcoming' => __('Upcoming cycle'),
            ],
            'loan' => [
                'icon' => 'heroicon-o-currency-dollar',
                'hint_complete' => __('Repaid'),
                'hint_current' => __('Repayment in progress'),
                'hint_upcoming' => __('No active loan'),
            ],
            'arrears' => [
                'icon' => 'heroicon-o-exclamation-triangle',
                'hint_warning' => __('Clear outstanding items'),
                'hint_complete' => __('Resolved'),
                'hint_upcoming' => __('All clear'),
            ],
        ];

        $statusLabels = [
            'complete' => __('Done'),
            'current' => __('Now'),
            'warning' => __('Attention'),
            'upcoming' => __('Next'),
        ];
    @endphp

    <nav aria-label="{{ __('Member lifecycle') }}" class="ff-member-journey__timeline w-full">
        <ol
            class="m-0 flex list-none gap-0 p-0 [-ms-overflow-style:none] [scrollbar-width:none] sm:min-w-full [&::-webkit-scrollbar]:hidden">
            @foreach ($steps as $index => $step)
                @php
                    $key = $step['key'] ?? 'step';
                    $meta = $stepMeta[$key] ?? ['icon' => 'heroicon-o-sparkles', 'hint_upcoming' => ''];
                    $state = $step['state'] ?? 'upcoming';
                    $isComplete = $state === 'complete';
                    $isCurrent = $state === 'current';
                    $isWarning = $state === 'warning';
                    $isLast = $index === count($steps) - 1;
                    $hintKey = 'hint_'.$state;
                    $hint = $meta[$hintKey] ?? ($meta['hint_upcoming'] ?? '');
                    $connectorComplete = $isComplete || $isCurrent;
                @endphp
                <li
                    class="ff-member-journey__step relative flex min-w-[4.75rem] flex-1 flex-col items-center px-1 sm:min-w-0 sm:px-1.5"
                    style="animation-delay: {{ $index * 0.06 }}s"
                >
                    @if (! $isLast)
                        <span
                            @class([
                                'ff-member-journey__connector pointer-events-none absolute top-[1.125rem] z-0 h-0.5 rounded-full',
                                'start-[calc(50%+1.125rem)] w-[calc(100%-2.25rem)]',
                                'bg-gradient-to-r from-emerald-400 to-emerald-300 dark:from-emerald-500 dark:to-emerald-600' => $connectorComplete,
                                'bg-gray-200 dark:bg-gray-700' => ! $connectorComplete,
                            ])
                            aria-hidden="true"
                        ></span>
                    @endif

                    <span
                        @class([
                            'ff-member-journey__node relative z-[1] flex h-9 w-9 items-center justify-center rounded-full border-2 shadow-sm transition-all duration-300 sm:h-10 sm:w-10',
                            'border-rose-500 bg-rose-500 text-white shadow-rose-900/25 ff-member-journey__node--pulse ff-member-journey__node--pulse-warning' => $isWarning,
                            'border-emerald-500 bg-emerald-500 text-white shadow-emerald-900/20 ff-member-journey__node--pulse' => $isCurrent && ! $isWarning,
                            'border-emerald-400 bg-emerald-50 text-emerald-700 dark:border-emerald-500/60 dark:bg-emerald-500/20 dark:text-emerald-200' => $isComplete && ! $isCurrent && ! $isWarning,
                            'border-gray-200 bg-white text-gray-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-500' => ! $isCurrent && ! $isComplete && ! $isWarning,
                        ])
                    >
                        @if ($isComplete && ! $isWarning)
                            <x-heroicon-s-check class="h-4 w-4 sm:h-5 sm:w-5" aria-hidden="true" />
                        @else
                            <x-dynamic-component :component="$meta['icon']" class="h-4 w-4 sm:h-[1.125rem] sm:w-[1.125rem]" aria-hidden="true" />
                        @endif
                    </span>

                    <span
                        class="mt-2 w-full truncate text-center text-[11px] font-bold leading-tight text-gray-900 dark:text-white sm:text-xs"
                    >
                        {{ $step['label'] }}
                    </span>

                    @if (filled($hint))
                        <span class="mt-0.5 line-clamp-2 w-full text-center text-[10px] leading-snug text-gray-500 dark:text-gray-400">
                            {{ $hint }}
                        </span>
                    @endif

                    <span
                        @class([
                            'mt-1.5 inline-flex max-w-full items-center justify-center rounded-full px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide',
                            'bg-rose-100 text-rose-800 ring-1 ring-rose-200/80 dark:bg-rose-500/20 dark:text-rose-200 dark:ring-rose-500/30' => $isWarning,
                            'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200/80 dark:bg-emerald-500/20 dark:text-emerald-200 dark:ring-emerald-500/30' => $isCurrent && ! $isWarning,
                            'bg-emerald-50/80 text-emerald-700 ring-1 ring-emerald-100 dark:bg-emerald-950/50 dark:text-emerald-300 dark:ring-emerald-800/50' => $isComplete && ! $isCurrent && ! $isWarning,
                            'bg-gray-100 text-gray-500 ring-1 ring-gray-200/80 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700' => ! $isCurrent && ! $isComplete && ! $isWarning,
                        ])
                    >
                        {{ $statusLabels[$state] ?? ucfirst($state) }}
                    </span>
                </li>
            @endforeach
        </ol>
    </nav>
@else
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
@endif
