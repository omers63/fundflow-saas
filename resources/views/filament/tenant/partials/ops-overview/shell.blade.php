{{--
  Ops overview outer card (member workspace-summary density).
  Use: @component('filament.tenant.partials.ops-overview.shell', [...]) ... @endcomponent
--}}
@php
    $title = $title ?? __('Overview');
    $subtitle = $subtitle ?? null;
    $badge = $badge ?? null;
    $badgeTone = is_array($badge) ? ($badge['tone'] ?? 'gray') : 'gray';
    $badgeLabel = is_array($badge) ? ($badge['label'] ?? null) : $badge;
    $wrapperClass = $wrapperClass ?? '';
    $sectionClass = $sectionClass ?? '';
@endphp

<div @class([
    'ff-app-insights ff-ops-overview mb-1 w-full max-w-none space-y-3',
    $wrapperClass,
])>
    <section @class([
        'ff-ops-overview__card overflow-hidden rounded-xl border border-gray-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900',
        $sectionClass,
    ])>
        <div
            class="ff-ops-overview__header flex flex-wrap items-center justify-between gap-2 border-b border-gray-200/80 px-3 py-2.5 dark:border-white/10">
            <div class="min-w-0">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    {{ $title }}
                </h2>
                @if (filled($subtitle))
                    <p class="truncate text-[11px] text-gray-400 dark:text-gray-500">
                        {!! \App\Filament\Support\MoneyDisplay::markupForDisplay($subtitle) !!}
                    </p>
                @endif
            </div>

            @if (filled($badgeLabel))
                <span @class([
                    'inline-flex max-w-full items-center rounded-full px-2.5 py-1 text-[11px] font-semibold',
                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' => in_array($badgeTone, ['success', 'emerald'], true),
                    'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' => in_array($badgeTone, ['warning', 'amber'], true),
                    'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300' => in_array($badgeTone, ['danger', 'rose'], true),
                    'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300' => $badgeTone === 'sky',
                    'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300' => $badgeTone === 'violet',
                    'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => ! in_array($badgeTone, ['success', 'emerald', 'warning', 'amber', 'danger', 'rose', 'sky', 'violet'], true),
                ])>
                    <span class="truncate">{{ $badgeLabel }}</span>
                </span>
            @endif
        </div>

        <div class="ff-ops-overview__body space-y-3 p-3">
            {{ $slot }}
        </div>
    </section>
</div>
