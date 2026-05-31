@php
    $g = $greeting;
@endphp

<div
    class="ff-member-dashboard-hero relative overflow-hidden rounded-2xl border border-emerald-200/50 bg-gradient-to-br from-emerald-500 via-emerald-600 to-teal-700 px-4 py-4 shadow-lg shadow-emerald-500/20 sm:px-6 sm:py-5 dark:border-emerald-500/20 dark:from-emerald-600 dark:via-emerald-700 dark:to-teal-900">
    <div class="pointer-events-none absolute -right-8 -top-8 h-32 w-32 rounded-full bg-white/10 blur-2xl"
        aria-hidden="true"></div>
    <div class="pointer-events-none absolute -bottom-10 left-1/4 h-24 w-24 rounded-full bg-teal-300/20 blur-2xl"
        aria-hidden="true"></div>

    <div class="relative flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 flex-1 gap-3 sm:items-center">
            <a href="{{ $g['profile_url'] }}"
                class="group relative flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-white/40 bg-white/20 text-lg font-bold text-white shadow-md ring-2 ring-white/20 transition hover:border-white/60 hover:bg-white/30 sm:h-[4.5rem] sm:w-[4.5rem]"
                title="{{ __('My profile') }}">
                @if (filled($g['avatar_url'] ?? null))
                    <img src="{{ $g['avatar_url'] }}" alt="{{ $g['name'] }}" loading="lazy" decoding="async"
                        class="absolute inset-0 h-full w-full object-cover">
                @else
                    <span class="select-none" aria-hidden="true">{{ $g['initials'] }}</span>
                @endif
                <span
                    class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-emerald-950/70 to-transparent py-0.5 text-center text-[9px] font-medium uppercase tracking-wide text-white opacity-0 transition group-hover:opacity-100">
                    {{ __('Profile') }}
                </span>
            </a>

            <div class="min-w-0 flex-1">
                <p class="text-[10px] font-medium uppercase tracking-widest text-emerald-100/90">{{ $g['date'] }}</p>
                <h2 class="mt-0.5 text-lg font-bold leading-tight text-white sm:text-xl">
                    {{ $g['period_label'] }}, {{ $g['name'] }}
                </h2>
                <p class="mt-0.5 text-xs text-emerald-100">{{ $g['fund_name'] }}</p>
                <p class="mt-1.5 line-clamp-2 text-xs leading-snug text-white/90 sm:text-sm">{{ $g['subtitle'] }}</p>
                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] text-emerald-100/90">
                    <span class="font-mono">{{ $g['member_number'] }}</span>
                    <span
                        class="inline-flex rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-semibold text-white ring-1 ring-white/25">
                        {{ $g['status_label'] }}
                    </span>
                    @if (filled($g['joined_label'] ?? null))
                        <span class="hidden sm:inline" aria-hidden="true">·</span>
                        <span class="hidden sm:inline">{{ $g['joined_label'] }}</span>
                    @endif
                </div>
                @if (filled($g['highlight_cta_url'] ?? null))
                    <a href="{{ $g['highlight_cta_url'] }}"
                        class="mt-2 inline-flex items-center gap-1 rounded-lg bg-white/20 px-3 py-1.5 text-[11px] font-semibold text-white ring-1 ring-white/30 transition hover:bg-white/30">
                        {{ $g['highlight_cta_label'] }} →
                    </a>
                @endif
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap gap-2 sm:justify-end">
            @foreach ($g['balances'] as $balance)
                <a href="{{ $balance['url'] }}"
                    class="ff-dashboard-balance group min-w-[6.75rem] rounded-xl bg-white/15 px-3 py-2 backdrop-blur-md ring-1 ring-white/25 transition hover:bg-white/25"
                    title="{{ $balance['full'] }}">
                    <div class="flex items-center gap-1.5">
                        <x-dynamic-component :component="$balance['icon']" class="h-3.5 w-3.5 text-emerald-100" />
                        <span
                            class="text-[10px] font-medium uppercase tracking-wide text-emerald-100">{{ $balance['label'] }}</span>
                    </div>
                    <p class="mt-0.5 text-sm font-bold tabular-nums text-white">{{ $balance['amount'] }}</p>
                </a>
            @endforeach
        </div>
    </div>

    @if (count($g['pills'] ?? []) > 0)
        <ul class="relative mt-3 flex flex-wrap gap-1.5 border-t border-white/15 pt-3">
            @foreach ($g['pills'] as $pill)
                <li>
                    @if (filled($pill['url'] ?? null))
                        <a href="{{ $pill['url'] }}"
                            class="inline-flex items-center gap-1 rounded-full bg-white/15 px-2.5 py-1 text-[10px] font-semibold text-white ring-1 ring-white/20 transition hover:bg-white/25">
                            <x-dynamic-component :component="$pill['icon']" class="h-3 w-3 shrink-0 opacity-90" />
                            {{ $pill['label'] }}
                        </a>
                    @else
                        <span
                            class="inline-flex items-center gap-1 rounded-full bg-white/10 px-2.5 py-1 text-[10px] font-semibold text-white/90 ring-1 ring-white/15">
                            <x-dynamic-component :component="$pill['icon']" class="h-3 w-3 shrink-0 opacity-90" />
                            {{ $pill['label'] }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>