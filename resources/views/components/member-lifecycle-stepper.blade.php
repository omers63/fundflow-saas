@props([
    'steps' => [],
    'variant' => 'pipeline',
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
    @php
        $stepMeta = [
            'joined' => ['icon' => 'heroicon-o-user-plus'],
            'active' => ['icon' => 'heroicon-o-check-badge'],
            'cycle' => ['icon' => 'heroicon-o-arrow-path'],
            'loan' => ['icon' => 'heroicon-o-currency-dollar'],
            'arrears' => ['icon' => 'heroicon-o-exclamation-triangle'],
        ];

        $totalSteps = count($steps);
        $completedCount = collect($steps)->where('state', 'complete')->count();
        $hasCurrent = collect($steps)->contains(fn (array $step): bool => in_array($step['state'] ?? '', ['current', 'warning'], true));
        $progressPercent = $totalSteps > 1
            ? min(100, (int) round((($completedCount + ($hasCurrent ? 0.45 : 0)) / max(1, $totalSteps - 1)) * 100))
            : 100;

        $focusStep = collect($steps)->first(fn (array $step): bool => in_array($step['state'] ?? '', ['current', 'warning'], true))
            ?? collect($steps)->last(fn (array $step): bool => ($step['state'] ?? '') === 'complete');
        $currentLabel = $focusStep['label'] ?? null;
        $currentDescription = $focusStep['description'] ?? null;
    @endphp

    <section
        class="ff-member-stepper px-1 pb-1 pt-3 sm:px-2"
        data-ff-member-ui="v2"
        aria-labelledby="ff-member-stepper-heading"
    >
        <div class="mb-3 flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <p id="ff-member-stepper-heading" class="text-[10px] font-bold uppercase tracking-[0.12em] text-gray-500 dark:text-gray-400">
                    {{ __('Member journey') }}
                </p>
                @if (filled($currentLabel))
                    <p class="mt-0.5 text-sm font-bold text-gray-900 dark:text-white sm:text-base">{{ $currentLabel }}</p>
                @endif
            </div>
            <span class="shrink-0 rounded-full bg-teal-100 px-2.5 py-0.5 text-[11px] font-bold tabular-nums text-teal-700 dark:bg-teal-950/50 dark:text-teal-200">
                {{ $progressPercent }}%
            </span>
        </div>

        <div class="h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700" aria-hidden="true">
            <div
                class="h-full rounded-full bg-gradient-to-r from-emerald-500 via-teal-500 to-sky-500 transition-all duration-500"
                style="width: {{ $progressPercent }}%"
            ></div>
        </div>

        <nav aria-label="{{ __('Member lifecycle') }}" class="-mx-1 mt-3 overflow-x-auto px-1 pb-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <ol class="m-0 flex min-w-full list-none gap-1 p-0">
                @foreach ($steps as $index => $step)
                    @php
                        $key = $step['key'] ?? 'step';
                        $meta = $stepMeta[$key] ?? ['icon' => 'heroicon-o-sparkles'];
                        $state = $step['state'] ?? 'upcoming';
                        $isComplete = $state === 'complete';
                        $isCurrent = $state === 'current';
                        $isWarning = $state === 'warning';
                        $isUpcoming = $state === 'upcoming';
                    @endphp
                    <li
                        class="flex min-w-[4.25rem] flex-1 flex-col items-center gap-1.5 sm:min-w-[5rem]"
                        @if ($isCurrent || $isWarning) aria-current="step" @endif
                    >
                        <span
                            @class([
                                'inline-flex h-8 w-8 items-center justify-center rounded-full border-2 shadow-sm transition',
                                'border-rose-500 bg-rose-500 text-white ring-4 ring-rose-500/20' => $isWarning,
                                'border-teal-500 bg-teal-500 text-white ring-4 ring-teal-500/25 scale-105' => $isCurrent && ! $isWarning,
                                'border-emerald-400 bg-emerald-50 text-emerald-600 dark:border-emerald-500/60 dark:bg-emerald-500/15 dark:text-emerald-300' => $isComplete && ! $isCurrent && ! $isWarning,
                                'border-gray-200 bg-white text-gray-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-500' => $isUpcoming,
                            ])
                        >
                            @if ($isComplete && ! $isWarning)
                                <x-heroicon-s-check class="h-4 w-4" aria-hidden="true" />
                            @else
                                <x-dynamic-component :component="$meta['icon']" class="h-4 w-4" aria-hidden="true" />
                            @endif
                        </span>
                        <span
                            @class([
                                'w-full text-center text-[10px] font-semibold leading-snug sm:text-[11px]',
                                'text-rose-700 dark:text-rose-300' => $isWarning,
                                'text-teal-700 dark:text-teal-300' => $isCurrent && ! $isWarning,
                                'text-emerald-700 dark:text-emerald-300' => $isComplete && ! $isCurrent,
                                'text-gray-500 dark:text-gray-400' => $isUpcoming,
                            ])
                        >
                            {{ $step['label'] }}
                        </span>
                    </li>
                @endforeach
            </ol>
        </nav>

        @if (filled($currentDescription))
            <p class="mt-2 rounded-lg bg-gray-100/90 px-3 py-2 text-[11px] font-medium leading-relaxed text-gray-600 dark:bg-gray-800/80 dark:text-gray-300">
                {{ $currentDescription }}
            </p>
        @endif
    </section>
@endif
