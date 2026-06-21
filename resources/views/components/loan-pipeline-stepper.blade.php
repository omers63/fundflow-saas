@props([
    'steps' => [],
])

@php
    $stepMeta = [
        'applied' => ['icon' => 'heroicon-o-document-text'],
        'under_review' => ['icon' => 'heroicon-o-magnifying-glass-circle'],
        'approved' => ['icon' => 'heroicon-o-check-badge'],
        'disbursed' => ['icon' => 'heroicon-o-banknotes'],
        'active' => ['icon' => 'heroicon-o-play-circle'],
        'repaying' => ['icon' => 'heroicon-o-arrow-path'],
        'settled' => ['icon' => 'heroicon-o-check-circle'],
        'closed' => ['icon' => 'heroicon-o-lock-closed'],
    ];

    $isShortTerminal = count($steps) === 3 && ($steps[2]['key'] ?? null) === 'closed';
@endphp

<section
    class="ff-loan-pipeline mb-4 rounded-xl border border-gray-200/80 bg-gradient-to-br from-white via-sky-50/40 to-indigo-50/30 px-3 py-3 shadow-sm dark:border-gray-700 dark:from-gray-900 dark:via-sky-950/20 dark:to-indigo-950/20 sm:px-4 sm:py-4"
    aria-labelledby="ff-loan-pipeline-heading"
>
    <div class="mb-3 flex items-center gap-2">
        <span
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-sky-500 to-indigo-600 text-white shadow-sm shadow-sky-900/20"
            aria-hidden="true"
        >
            <x-heroicon-o-arrow-trending-up class="h-4 w-4" />
        </span>
        <div class="min-w-0">
            <h2 id="ff-loan-pipeline-heading" class="text-sm font-bold text-gray-900 dark:text-white">
                {{ __('Loan lifecycle') }}
            </h2>
            <p class="text-[10px] text-gray-500 dark:text-gray-400">
                {{ __('Track each stage from application to closure') }}
            </p>
        </div>
    </div>

    <nav
        aria-label="{{ __('Loan progress') }}"
        class="ff-loan-pipeline__nav -mx-1 overflow-x-auto px-1 pb-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    >
        <ol @class([
            'ff-loan-pipeline__track m-0 flex list-none p-0',
            'min-w-full' => ! $isShortTerminal,
            'min-w-max justify-center gap-2 sm:gap-4' => $isShortTerminal,
        ])>
            @foreach ($steps as $index => $step)
                @php
                    $key = $step['key'] ?? 'step';
                    $meta = $stepMeta[$key] ?? ['icon' => 'heroicon-o-sparkles'];
                    $state = $step['state'] ?? 'upcoming';
                    $isComplete = $state === 'complete';
                    $isCurrent = $state === 'current';
                    $isUpcoming = $state === 'upcoming';
                    $isLast = $index === count($steps) - 1;
                    $isTerminalFailure = $isShortTerminal && $isCurrent && $key === 'closed';
                    $connectorComplete = $isComplete;
                    $description = $step['description'] ?? null;
                    $showDescription = filled($description);
                    $terminalIcon = $isTerminalFailure ? 'heroicon-o-x-circle' : $meta['icon'];
                @endphp
                <li
                    class="ff-loan-pipeline__step flex min-w-[5.5rem] flex-1 flex-col items-stretch sm:min-w-[6.25rem]"
                    style="animation-delay: {{ $index * 0.04 }}s"
                >
                    <div class="ff-loan-pipeline__rail relative flex h-10 w-full items-center justify-center">
                        @if (! $isLast)
                            <span
                                @class([
                                    'ff-loan-pipeline__connector pointer-events-none absolute top-1/2 z-0 h-0.5 -translate-y-1/2 rounded-full',
                                    'start-[calc(50%+1.25rem)] w-[calc(100%-2.5rem)]',
                                    'bg-emerald-400 dark:bg-emerald-500' => $connectorComplete,
                                    'bg-gray-200 dark:bg-gray-700' => ! $connectorComplete,
                                ])
                                aria-hidden="true"
                            ></span>
                            <span
                                @class([
                                    'ff-loan-pipeline__arrow pointer-events-none absolute top-1/2 z-[1] flex h-4 w-4 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border bg-white shadow-sm dark:bg-gray-900',
                                    'start-[calc(100%-0.125rem)]',
                                    'border-emerald-300 text-emerald-500 dark:border-emerald-600 dark:text-emerald-400' => $connectorComplete,
                                    'border-gray-200 text-gray-300 dark:border-gray-600 dark:text-gray-500' => ! $connectorComplete,
                                ])
                                aria-hidden="true"
                            >
                                <x-heroicon-s-chevron-right class="h-2.5 w-2.5 rtl:rotate-180" />
                            </span>
                        @endif

                        <span
                            @class([
                                'ff-loan-pipeline__node relative z-[2] flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 shadow-sm transition-all duration-300',
                                'border-rose-500 bg-rose-500 text-white shadow-rose-900/20 ff-loan-pipeline__node--pulse ff-loan-pipeline__node--pulse-warning' => $isTerminalFailure,
                                'border-sky-500 bg-sky-500 text-white shadow-sky-900/15 ff-loan-pipeline__node--pulse' => $isCurrent && ! $isTerminalFailure,
                                'border-emerald-400 bg-emerald-50 text-emerald-700 dark:border-emerald-500/60 dark:bg-emerald-500/20 dark:text-emerald-200' => $isComplete && ! $isCurrent,
                                'border-gray-200 bg-white text-gray-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-500' => $isUpcoming,
                            ])
                            @if ($isCurrent)
                                aria-current="step"
                            @endif
                        >
                            @if ($isComplete && ! $isTerminalFailure)
                                <x-heroicon-s-check class="h-4 w-4" aria-hidden="true" />
                            @else
                                <x-dynamic-component :component="$terminalIcon" class="h-4 w-4" aria-hidden="true" />
                            @endif
                        </span>
                    </div>

                    <div class="ff-loan-pipeline__body mt-2 flex min-h-[2.75rem] flex-col items-center px-0.5 text-center">
                        <span
                            @class([
                                'w-full text-[11px] font-semibold leading-snug sm:text-xs',
                                'text-rose-700 dark:text-rose-300' => $isTerminalFailure,
                                'text-sky-700 dark:text-sky-300' => $isCurrent && ! $isTerminalFailure,
                                'text-emerald-700 dark:text-emerald-300' => $isComplete && ! $isCurrent,
                                'text-gray-500 dark:text-gray-400' => $isUpcoming,
                            ])
                        >
                            {{ $step['label'] }}
                        </span>

                        @if ($showDescription)
                            <span class="mt-1 w-full text-[10px] font-medium leading-snug text-gray-600 dark:text-gray-300">
                                {{ $description }}
                            </span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </nav>
</section>
