@props(['gauge'])

@php
    $tone = $gauge['tone'] ?? 'sky';
    $stroke = match ($tone) {
        'emerald' => ['track' => 'stroke-emerald-200 dark:stroke-emerald-900/50', 'fill' => 'stroke-emerald-500', 'text' => 'text-emerald-600 dark:text-emerald-400'],
        'amber' => ['track' => 'stroke-amber-200 dark:stroke-amber-900/50', 'fill' => 'stroke-amber-500', 'text' => 'text-amber-600 dark:text-amber-400'],
        'rose' => ['track' => 'stroke-rose-200 dark:stroke-rose-900/50', 'fill' => 'stroke-rose-500', 'text' => 'text-rose-600 dark:text-rose-400'],
        'violet' => ['track' => 'stroke-violet-200 dark:stroke-violet-900/50', 'fill' => 'stroke-violet-500', 'text' => 'text-violet-600 dark:text-violet-400'],
        default => ['track' => 'stroke-sky-200 dark:stroke-sky-900/50', 'fill' => 'stroke-sky-500', 'text' => 'text-sky-600 dark:text-sky-400'],
    };
    $pct = min(100, max(0, (float) ($gauge['percent'] ?? 0)));
    $dash = round($pct * 0.88, 1);
@endphp

<a href="{{ $gauge['url'] ?? '#' }}"
    class="ff-dashboard-gauge group flex flex-col items-center rounded-xl border border-white/60 bg-white/70 px-2 py-3 shadow-sm backdrop-blur-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-gray-700/60 dark:bg-gray-800/70">
    <div class="relative mx-auto h-16 w-16 sm:h-[4.5rem] sm:w-[4.5rem]">
        <svg class="h-full w-full -rotate-90" viewBox="0 0 36 36" aria-hidden="true">
            <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3" @class(['opacity-80', $stroke['track']]) />
            <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3" stroke-linecap="round"
                @class([$stroke['fill'], 'ff-gauge-ring transition-all duration-700 ease-out'])
                stroke-dasharray="{{ $dash }}, 100" pathLength="100" />
        </svg>
        <span @class(['absolute inset-0 flex items-center justify-center text-sm font-bold tabular-nums', $stroke['text']])>
            {{ $gauge['value'] }}
        </span>
    </div>
    <p class="mt-1.5 text-center text-[10px] font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
        {{ ui_label($gauge['label']) }}
    </p>
    <p class="mt-0.5 line-clamp-2 text-center text-[10px] text-gray-400">{{ ui_label($gauge['sub']) }}</p>
</a>