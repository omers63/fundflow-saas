@php
$hero = $hero ?? [];
$compact = (bool) ($compact ?? true);
$tone = $hero['tone'] ?? 'success';
$borderBg = match ($tone) {
    'danger' => 'border-rose-200/90 bg-rose-50 text-rose-800 dark:border-rose-800/40 dark:bg-rose-950/30 dark:text-rose-300',
    'warning', 'amber' => 'border-amber-200/90 bg-amber-50 text-amber-800 dark:border-amber-800/40 dark:bg-amber-950/30 dark:text-amber-300',
    'sky' => 'border-sky-200/90 bg-sky-50 text-sky-800 dark:border-sky-800/40 dark:bg-sky-950/30 dark:text-sky-300',
    'violet' => 'border-violet-200/90 bg-violet-50 text-violet-800 dark:border-violet-800/40 dark:bg-violet-950/30 dark:text-violet-300',
    default => 'border-emerald-200/90 bg-emerald-50 text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-950/30 dark:text-emerald-300',
};
$iconName = match ($tone) {
    'danger' => 'heroicon-o-exclamation-triangle',
    'warning', 'amber' => 'heroicon-o-bolt',
    'sky' => 'heroicon-o-sparkles',
    'violet' => 'heroicon-o-queue-list',
    default => 'heroicon-o-check-badge',
};
$ctaBg = 'border border-gray-200/90 bg-white text-gray-600 hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-indigo-600 dark:hover:bg-indigo-950/30 dark:hover:text-indigo-300';
@endphp

<div @class([
    'ff-app-insights-hero ff-ops-overview__hero flex items-center rounded-lg border',
    'gap-2 px-3 py-2',
    $borderBg,
])>
    <x-dynamic-component :component="$iconName" class="mt-0.5 h-4 w-4 shrink-0" />
    <div class="min-w-0 flex-1">
        <p class="text-[11px] font-semibold leading-snug">{{ $hero['title'] ?? '' }}</p>
        @if (filled($hero['subtitle'] ?? null))
            <p class="mt-0 text-[10px] leading-snug opacity-80">
                {!! \App\Filament\Support\MoneyDisplay::markupForDisplay($hero['subtitle']) !!}
            </p>
        @endif
    </div>
    @if (! empty($hero['cta_url'] ?? null))
        <a href="{{ $hero['cta_url'] }}" @class([
            'ms-auto shrink-0 rounded-lg px-3 py-1 text-[10px] font-semibold transition',
            $ctaBg,
        ])>
            {{ $hero['cta_label'] }}
        </a>
    @endif
</div>
