@props([
    'steps' => [],
    'currentStep' => 1,
])

<nav aria-label="{{ __('Enrollment progress') }}" class="mb-6 w-full">
    <ol class="m-0 flex list-none flex-row items-stretch gap-1 p-0 sm:gap-1.5">
        @foreach ($steps as $index => $step)
            @php
                $number = $index + 1;
                $isActive = $currentStep === $number;
                $isComplete = $currentStep > $number;
            @endphp
            <li
                @class([
                    'flex min-w-0 flex-1 flex-col items-center rounded-lg border px-1 py-1.5 text-center transition-all sm:px-1.5 sm:py-2',
                    'border-emerald-500 bg-emerald-50/80 ring-1 ring-emerald-500/25' => $isActive,
                    'border-emerald-200 bg-emerald-50/40' => $isComplete && ! $isActive,
                    'border-gray-200 bg-white' => ! $isActive && ! $isComplete,
                ])
            >
                <span
                    @class([
                        'mb-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold sm:mb-1 sm:h-7 sm:w-7 sm:text-xs',
                        'bg-emerald-600 text-white' => $isActive,
                        'bg-emerald-500 text-white' => $isComplete,
                        'bg-gray-100 text-gray-500' => ! $isActive && ! $isComplete,
                    ])
                >
                    @if ($isComplete)
                        <svg class="h-3 w-3 sm:h-3.5 sm:w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    @else
                        {{ $number }}
                    @endif
                </span>

                <span @class([
                    'w-full truncate px-0.5 text-[10px] font-semibold leading-tight sm:text-xs',
                    'text-emerald-800' => $isActive,
                    'text-gray-900' => ! $isActive,
                ])>
                    {{ $step['label'] }}
                </span>
                <span class="mt-0.5 w-full truncate px-0.5 text-[9px] leading-tight text-gray-500 sm:text-[10px]">
                    {{ $step['subtitle'] }}
                </span>
            </li>
        @endforeach
    </ol>
</nav>
