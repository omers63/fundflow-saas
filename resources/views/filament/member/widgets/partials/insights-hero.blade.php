@props(['hero'])

@php
    $toneClasses = match ($hero['tone'] ?? 'success') {
        'danger' => 'border-rose-200/80 bg-gradient-to-r from-rose-50 to-amber-50/80 dark:border-rose-500/30 dark:from-rose-950/40 dark:to-amber-950/20',
        'warning', 'amber' => 'border-amber-200/80 bg-gradient-to-r from-amber-50 to-sky-50/80 dark:border-amber-500/30 dark:from-amber-950/40 dark:to-sky-950/20',
        'sky' => 'border-sky-200/80 bg-gradient-to-r from-sky-50 to-indigo-50/80 dark:border-sky-500/30 dark:from-sky-950/40 dark:to-indigo-950/20',
        default => 'border-emerald-200/70 bg-gradient-to-r from-emerald-50 to-teal-50/60 dark:border-emerald-500/25 dark:from-emerald-950/30 dark:to-teal-950/20',
    };
    $icon = match ($hero['tone'] ?? 'success') {
        'danger' => 'heroicon-o-exclamation-triangle',
        'warning', 'amber' => 'heroicon-o-bolt',
        'sky' => 'heroicon-o-sparkles',
        default => 'heroicon-o-check-badge',
    };
    $iconColor = match ($hero['tone'] ?? 'success') {
        'danger' => 'text-rose-600 dark:text-rose-400',
        'warning', 'amber' => 'text-amber-600 dark:text-amber-400',
        'sky' => 'text-sky-600 dark:text-sky-400',
        default => 'text-emerald-600 dark:text-emerald-400',
    };
    $ctaClass = match ($hero['tone'] ?? 'success') {
        'danger' => 'bg-rose-600 hover:bg-rose-500 dark:bg-rose-500',
        'warning', 'amber' => 'bg-amber-600 hover:bg-amber-500 dark:bg-amber-500',
        'sky' => 'bg-sky-600 hover:bg-sky-500 dark:bg-sky-500',
        default => 'bg-emerald-600 hover:bg-emerald-500 dark:bg-emerald-500',
    };
@endphp

<div @class(['ff-app-insights-hero overflow-hidden rounded-xl border px-3 py-2.5 shadow-sm', $toneClasses])>
    <div class="flex items-center justify-between gap-2">
        <div class="flex min-w-0 items-center gap-2">
            <x-dynamic-component :component="$icon" @class(['h-4 w-4 shrink-0', $iconColor]) />
            <div class="min-w-0">
                <p class="truncate text-xs font-semibold text-gray-900 dark:text-white">{{ $hero['title'] }}</p>
                <p class="truncate text-[11px] text-gray-600 dark:text-gray-400">{{ $hero['subtitle'] }}</p>
            </div>
        </div>
        @if (!empty($hero['cta_url'] ?? null))
            <a href="{{ $hero['cta_url'] }}" @class(['shrink-0 rounded-lg px-2.5 py-1 text-[11px] font-semibold text-white', $ctaClass])>
                {{ $hero['cta_label'] }}
            </a>
        @endif
    </div>
</div>