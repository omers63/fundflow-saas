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
    $totalSteps = count($steps);
    $completedCount = collect($steps)->where('state', 'complete')->count();
    $hasCurrent = collect($steps)->contains(fn (array $step): bool => ($step['state'] ?? '') === 'current');
    $progressPercent = $totalSteps > 1
        ? min(100, (int) round((($completedCount + ($hasCurrent ? 0.45 : 0)) / max(1, $totalSteps - 1)) * 100))
        : 100;

    $currentStep = collect($steps)->first(fn (array $step): bool => ($step['state'] ?? '') === 'current');
    $currentDescription = $currentStep['description'] ?? null;
    $currentLabel = $currentStep['label'] ?? null;
@endphp

<section
    class="ff-loan-stepper px-1 pb-1 pt-3 sm:px-2"
    data-ff-loan-ui="v2"
    aria-labelledby="ff-loan-stepper-heading"
>
    <div class="mb-3 flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <p id="ff-loan-stepper-heading" class="text-[10px] font-bold uppercase tracking-[0.12em] text-gray-500 dark:text-gray-400">
                {{ __('Loan progress') }}
            </p>
            @if (filled($currentLabel))
                <p class="mt-0.5 text-sm font-bold text-gray-900 dark:text-white sm:text-base">{{ $currentLabel }}</p>
            @endif
        </div>
        <span class="shrink-0 rounded-full bg-sky-100 px-2.5 py-0.5 text-[11px] font-bold tabular-nums text-sky-700 dark:bg-sky-950/50 dark:text-sky-200">
            {{ $progressPercent }}%
        </span>
    </div>

    <div class="h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700" aria-hidden="true">
        <div
            class="h-full rounded-full bg-gradient-to-r from-emerald-500 via-sky-500 to-indigo-500 transition-all duration-500"
            style="width: {{ $progressPercent }}%"
        ></div>
    </div>

    <nav aria-label="{{ __('Loan progress') }}" class="-mx-1 mt-3 overflow-x-auto px-1 pb-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        <ol @class([
            'm-0 flex list-none gap-1 p-0',
            'min-w-full' => ! $isShortTerminal,
            'min-w-max justify-center gap-4' => $isShortTerminal,
        ])>
            @foreach ($steps as $index => $step)
                @php
                    $key = $step['key'] ?? 'step';
                    $meta = $stepMeta[$key] ?? ['icon' => 'heroicon-o-sparkles'];
                    $state = $step['state'] ?? 'upcoming';
                    $isComplete = $state === 'complete';
                    $isCurrent = $state === 'current';
                    $isUpcoming = $state === 'upcoming';
                    $isTerminalFailure = $isShortTerminal && $isCurrent && $key === 'closed';
                    $terminalIcon = $isTerminalFailure ? 'heroicon-o-x-circle' : $meta['icon'];
                @endphp
                <li
                    class="flex min-w-[4.25rem] flex-1 flex-col items-center gap-1.5 sm:min-w-[5rem]"
                    @if ($isCurrent) aria-current="step" @endif
                >
                    <span
                        @class([
                            'inline-flex h-8 w-8 items-center justify-center rounded-full border-2 shadow-sm transition',
                            'border-rose-500 bg-rose-500 text-white ring-4 ring-rose-500/20' => $isTerminalFailure,
                            'border-sky-500 bg-sky-500 text-white ring-4 ring-sky-500/25 scale-105' => $isCurrent && ! $isTerminalFailure,
                            'border-emerald-400 bg-emerald-50 text-emerald-600 dark:border-emerald-500/60 dark:bg-emerald-500/15 dark:text-emerald-300' => $isComplete && ! $isCurrent,
                            'border-gray-200 bg-white text-gray-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-500' => $isUpcoming,
                        ])
                    >
                        @if ($isComplete && ! $isTerminalFailure)
                            <x-heroicon-s-check class="h-4 w-4" aria-hidden="true" />
                        @else
                            <x-dynamic-component :component="$terminalIcon" class="h-4 w-4" aria-hidden="true" />
                        @endif
                    </span>
                    <span
                        @class([
                            'w-full text-center text-[10px] font-semibold leading-snug sm:text-[11px]',
                            'text-rose-700 dark:text-rose-300' => $isTerminalFailure,
                            'text-sky-700 dark:text-sky-300' => $isCurrent && ! $isTerminalFailure,
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
