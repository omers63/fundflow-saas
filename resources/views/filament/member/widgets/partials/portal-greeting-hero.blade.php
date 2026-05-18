@php
    $g = $greeting;
    $highlightTone = $g['highlight_tone'] ?? 'success';

    $goldHighlightShell = 'border-amber-300/90 bg-gradient-to-br from-amber-50 via-amber-100/50 to-white ring-1 ring-amber-200/80 dark:border-amber-500/40 dark:from-amber-950/90 dark:via-amber-900/70 dark:to-gray-900 dark:ring-amber-500/25';

    $highlightStyles = match ($highlightTone) {
        'danger' => [
            'box' => $goldHighlightShell,
            'accent' => 'border-l-4 border-l-rose-500 dark:border-l-rose-400',
            'title' => 'text-amber-950 dark:text-amber-50',
            'sub' => 'text-amber-900/85 dark:text-amber-100/90',
            'cta' => 'bg-gradient-to-r from-amber-600 to-yellow-600 text-white shadow-sm shadow-amber-900/25 hover:from-amber-500 hover:to-yellow-500 dark:from-amber-500 dark:to-yellow-500',
        ],
        'sky' => [
            'box' => $goldHighlightShell,
            'accent' => 'border-l-4 border-l-sky-600 dark:border-l-sky-400',
            'title' => 'text-amber-950 dark:text-amber-50',
            'sub' => 'text-amber-900/85 dark:text-amber-100/90',
            'cta' => 'bg-gradient-to-r from-amber-600 to-yellow-600 text-white shadow-sm shadow-amber-900/25 hover:from-amber-500 hover:to-yellow-500 dark:from-amber-500 dark:to-yellow-500',
        ],
        'amber', 'warning' => [
            'box' => $goldHighlightShell,
            'accent' => 'border-l-4 border-l-amber-500 dark:border-l-amber-400',
            'title' => 'text-amber-950 dark:text-amber-50',
            'sub' => 'text-amber-900/85 dark:text-amber-100/90',
            'cta' => 'bg-gradient-to-r from-amber-600 to-yellow-600 text-white shadow-sm shadow-amber-900/25 hover:from-amber-500 hover:to-yellow-500 dark:from-amber-500 dark:to-yellow-500',
        ],
        default => [
            'box' => $goldHighlightShell,
            'accent' => 'border-l-4 border-l-yellow-600 dark:border-l-yellow-500',
            'title' => 'text-amber-950 dark:text-amber-50',
            'sub' => 'text-amber-900/85 dark:text-amber-100/90',
            'cta' => 'bg-gradient-to-r from-amber-600 to-yellow-600 text-white shadow-sm shadow-amber-900/25 hover:from-amber-500 hover:to-yellow-500 dark:from-amber-500 dark:to-yellow-500',
        ],
    };

    $pillStyles = [
        [
            'shell' => 'border-amber-300/90 bg-gradient-to-br from-amber-50 via-amber-100/40 to-white ring-amber-200/80 dark:border-amber-500/35 dark:from-amber-950/90 dark:via-amber-900/70 dark:to-gray-900 dark:ring-amber-500/25',
            'text' => 'text-amber-950 dark:text-amber-50',
            'icon' => 'text-amber-700 dark:text-amber-400',
        ],
        [
            'shell' => 'border-yellow-300/90 bg-gradient-to-br from-yellow-50 via-amber-50 to-white ring-yellow-200/80 dark:border-yellow-500/35 dark:from-yellow-950/85 dark:via-amber-950/75 dark:to-gray-900 dark:ring-yellow-500/20',
            'text' => 'text-yellow-950 dark:text-yellow-50',
            'icon' => 'text-yellow-700 dark:text-yellow-400',
        ],
    ];
@endphp

<div
    class="ff-member-dashboard-hero relative overflow-hidden rounded-2xl border border-emerald-300/40 bg-gradient-to-br from-emerald-600 via-emerald-600 to-teal-700 px-4 py-4 shadow-lg shadow-emerald-600/25 sm:px-6 sm:py-5 dark:border-emerald-500/25 dark:from-emerald-800 dark:via-emerald-900 dark:to-teal-950">
    <div class="pointer-events-none absolute -right-10 -top-10 h-44 w-44 rounded-full bg-white/10 blur-2xl"
        aria-hidden="true">
    </div>
    <div class="pointer-events-none absolute -bottom-14 left-1/3 h-36 w-36 rounded-full bg-teal-300/20 blur-2xl"
        aria-hidden="true"></div>
    <div class="pointer-events-none absolute right-1/4 top-1/2 h-24 w-24 rounded-full bg-lime-300/15 blur-xl"
        aria-hidden="true"></div>

    <div class="relative flex flex-col gap-4 lg:flex-row lg:items-stretch lg:justify-between">
        <div class="flex min-w-0 flex-1 gap-4 sm:items-center">
            <a href="{{ $g['profile_url'] }}"
                class="group relative flex h-[4.5rem] w-[4.5rem] shrink-0 items-center justify-center overflow-hidden rounded-2xl border-2 border-white/35 bg-white/15 text-2xl font-bold text-white shadow-inner ring-2 ring-white/20 transition hover:border-white/50 hover:bg-white/25 sm:h-20 sm:w-20"
                title="{{ __('My profile') }}">
                @if (filled($g['avatar_url'] ?? null))
                    <img src="{{ $g['avatar_url'] }}" alt="{{ $g['name'] }}"
                        class="absolute inset-0 h-full w-full object-cover">
                @else
                    <span class="select-none drop-shadow-sm">{{ $g['initials'] }}</span>
                @endif
                <span
                    class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/40 to-transparent py-1 text-center text-[9px] font-medium uppercase tracking-wide text-white/90 opacity-0 transition group-hover:opacity-100">
                    {{ __('Profile') }}
                </span>
            </a>

            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-medium uppercase tracking-[0.2em] text-emerald-100/90">
                    {{ $g['date'] }}
                </p>
                <h2 class="mt-1 text-xl font-bold leading-tight text-white sm:text-2xl">
                    {{ $g['period_label'] }}, {{ $g['first_name'] }}
                </h2>
                <p class="mt-0.5 text-sm font-medium text-emerald-50/95">{{ $g['fund_name'] }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="font-mono text-xs text-emerald-100/90">{{ $g['member_number'] }}</span>
                    @if (filled($g['joined_label'] ?? null))
                        <span class="hidden text-emerald-100/50 sm:inline" aria-hidden="true">·</span>
                        <span class="hidden text-xs text-emerald-100/80 sm:inline">{{ $g['joined_label'] }}</span>
                    @endif
                    <span
                        class="member-profile-status member-profile-status--{{ $g['status_tone'] }} !border-white/30 !bg-white/15 !text-white">
                        {{ $g['status_label'] }}
                    </span>
                </div>
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap gap-2 sm:justify-end lg:max-w-[16rem] lg:flex-col lg:justify-center">
            @foreach ($g['balances'] as $balance)
                @php
                    $balanceCard = match ($balance['variant'] ?? 'cash') {
                        'fund' => [
                            'shell' => 'border-yellow-300/90 bg-gradient-to-br from-yellow-50 via-amber-50 to-white ring-1 ring-yellow-200/80 dark:border-yellow-500/35 dark:from-yellow-950/85 dark:via-amber-950/75 dark:to-gray-900 dark:ring-yellow-500/20',
                            'accent' => 'border-l-4 border-l-yellow-600 dark:border-l-yellow-500',
                            'icon' => 'text-yellow-700 dark:text-yellow-400',
                            'label' => 'text-yellow-900/90 dark:text-yellow-100',
                            'amount' => 'text-gray-900 dark:text-white',
                        ],
                        default => [
                            'shell' => 'border-amber-300/90 bg-gradient-to-br from-amber-50 via-amber-100/40 to-white ring-1 ring-amber-200/80 dark:border-amber-500/40 dark:from-amber-950/90 dark:via-amber-900/70 dark:to-gray-900 dark:ring-amber-500/25',
                            'accent' => 'border-l-4 border-l-amber-500 dark:border-l-amber-400',
                            'icon' => 'text-amber-700 dark:text-amber-400',
                            'label' => 'text-amber-900/90 dark:text-amber-100',
                            'amount' => 'text-amber-950 dark:text-amber-50',
                        ],
                    };
                @endphp
                <a href="{{ $balance['url'] }}" @class([
                    'ff-member-dashboard-balance group min-w-[7.5rem] flex-1 rounded-xl px-3 py-2.5 shadow-md shadow-emerald-950/20 transition hover:-translate-y-0.5 hover:shadow-lg sm:flex-none lg:min-w-[8.5rem]',
                    $balanceCard['shell'],
                    $balanceCard['accent'],
                ])
                    title="{{ $balance['full'] }}">
                    <div class="flex items-center gap-1.5">
                        <x-dynamic-component :component="$balance['icon']" @class(['h-4 w-4 shrink-0', $balanceCard['icon']]) />
                        <span @class(['text-[10px] font-semibold uppercase tracking-wide', $balanceCard['label']])>
                            {{ $balance['label'] }}
                        </span>
                    </div>
                    <p @class(['mt-1 text-base font-bold tabular-nums sm:text-lg', $balanceCard['amount']])>
                        {{ $balance['amount'] }}
                    </p>
                </a>
            @endforeach
        </div>
    </div>

    <div class="relative mt-4 border-t border-white/15 pt-4">
        @if (filled($g['highlight_cta_url'] ?? null))
            <div @class([
                'ff-member-dashboard-hero-highlight mb-3 flex flex-col gap-2 rounded-xl px-3 py-2.5 shadow-md shadow-emerald-950/15 sm:flex-row sm:items-center sm:justify-between',
                $highlightStyles['box'],
                $highlightStyles['accent'],
            ])>
                <div class="min-w-0 pl-0.5">
                    <p @class(['text-sm font-semibold', $highlightStyles['title']])>{{ $g['highlight_title'] }}</p>
                    <p @class(['mt-0.5 text-xs', $highlightStyles['sub']])>{{ $g['subtitle'] }}</p>
                </div>
                <a href="{{ $g['highlight_cta_url'] }}" @class([
                    'inline-flex shrink-0 items-center justify-center rounded-lg px-3 py-1.5 text-xs font-semibold shadow-sm transition',
                    $highlightStyles['cta'],
                ])>
                    {{ $g['highlight_cta_label'] }} →
                </a>
            </div>
        @else
            <p class="max-w-3xl text-sm leading-relaxed text-white/90">{{ $g['subtitle'] }}</p>
        @endif

        @if (count($g['pills'] ?? []) > 0)
            <ul class="mt-3 flex flex-wrap gap-2">
                @foreach ($g['pills'] as $pill)
                    @php
                        $pillCard = $pillStyles[$loop->index % count($pillStyles)];
                    @endphp
                    <li>
                        @if (filled($pill['url'] ?? null))
                            <a href="{{ $pill['url'] }}" @class([
                                'ff-member-dashboard-pill inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-[11px] font-semibold shadow-md shadow-emerald-950/15 ring-1 transition hover:-translate-y-0.5 hover:shadow-lg',
                                $pillCard['shell'],
                                $pillCard['text'],
                            ])>
                                <x-dynamic-component :component="$pill['icon']" @class(['h-3.5 w-3.5 shrink-0', $pillCard['icon']]) />
                                {{ $pill['label'] }}
                            </a>
                        @else
                            <span @class([
                                'ff-member-dashboard-pill inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-[11px] font-semibold shadow-md shadow-emerald-950/15 ring-1',
                                $pillCard['shell'],
                                $pillCard['text'],
                            ])>
                                <x-dynamic-component :component="$pill['icon']" @class(['h-3.5 w-3.5 shrink-0', $pillCard['icon']]) />
                                {{ $pill['label'] }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>