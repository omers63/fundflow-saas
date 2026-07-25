@php
    $hero = $hero ?? [];
    $compact = (bool) ($compact ?? false);
    $tone = $hero['tone'] ?? 'success';
    $borderBg = match ($tone) {
        'danger' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-800/40 dark:bg-red-950/30 dark:text-red-300',
        'warning', 'amber' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800/40 dark:bg-amber-950/30 dark:text-amber-300',
        'sky' => 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800/40 dark:bg-sky-950/30 dark:text-sky-300',
        default => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-950/30 dark:text-emerald-300',
    };
    $iconName = match ($tone) {
        'danger' => 'heroicon-o-exclamation-triangle',
        'warning', 'amber' => 'heroicon-o-bolt',
        'sky' => 'heroicon-o-sparkles',
        default => 'heroicon-o-check-badge',
    };
    $ctaBg = 'border border-gray-200 bg-white text-gray-600 hover:border-sky-300 hover:bg-sky-50 hover:text-sky-700 dark:border-white/10 dark:bg-slate-800 dark:text-gray-300 dark:hover:border-sky-600 dark:hover:bg-sky-950/30 dark:hover:text-sky-300';
@endphp

<div @class([
    'ff-app-insights-hero flex items-center shadow-sm rounded-xl border',
    'gap-2 px-3 py-2' => $compact,
    'gap-3 px-4 py-3' => !$compact,
    $borderBg,
])>
    <x-dynamic-component :component="$iconName" @class([
        'shrink-0 mt-0.5',
        'h-4 w-4' => $compact,
        'h-5 w-5' => !$compact,
    ]) />
    <div class="min-w-0 flex-1">
        <p @class([
            'font-semibold',
            'text-[11px] leading-snug' => $compact,
            'text-[12px]' => !$compact,
        ])>{{ $hero['title'] ?? '' }}</p>
        <p @class([
            'opacity-80',
            'mt-0 text-[10px] leading-snug' => $compact,
            'mt-0.5 text-[11px]' => !$compact,
        ])>{{ $hero['subtitle'] ?? '' }}</p>
    </div>
    @if (!empty($hero['cta_url'] ?? null))
        <a href="{{ $hero['cta_url'] }}" @class([
            'ms-auto shrink-0 rounded-lg font-semibold transition',
            'px-3 py-1 text-[10px]' => $compact,
            'px-4 py-1.5 text-[11px]' => !$compact,
            $ctaBg,
        ])>
            {{ $hero['cta_label'] }}
        </a>
    @endif
</div>