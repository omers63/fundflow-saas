@props(['steps'])

@if (count($steps) > 0)
    <section
        class="ff-member-journey relative overflow-hidden rounded-2xl border border-emerald-200/80 bg-gradient-to-br from-white via-emerald-50/50 to-teal-50/40 shadow-sm ring-1 ring-emerald-100/60 dark:border-emerald-500/25 dark:from-gray-900 dark:via-emerald-950/30 dark:to-teal-950/20 dark:ring-emerald-500/10"
        aria-labelledby="ff-member-journey-heading"
    >
        <div
            class="pointer-events-none absolute -end-10 -top-10 h-36 w-36 rounded-full bg-emerald-400/15 blur-2xl dark:bg-emerald-500/20"
            aria-hidden="true"
        ></div>
        <div
            class="pointer-events-none absolute -bottom-8 start-8 h-28 w-28 rounded-full bg-teal-400/10 blur-2xl dark:bg-teal-500/15"
            aria-hidden="true"
        ></div>

        <header class="relative flex flex-col gap-3 border-b border-emerald-100/80 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between dark:border-emerald-500/15">
            <div class="flex min-w-0 items-start gap-3">
                <span
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-md shadow-emerald-900/20 ring-2 ring-white/80 dark:ring-emerald-950/50"
                    aria-hidden="true"
                >
                    <x-heroicon-o-map class="h-5 w-5" />
                </span>
                <div class="min-w-0 pt-0.5">
                    <h2 id="ff-member-journey-heading" class="text-sm font-bold text-gray-900 dark:text-white sm:text-base">
                        {{ __('Your membership journey') }}
                    </h2>
                    <p class="mt-0.5 text-xs leading-relaxed text-gray-600 dark:text-gray-400">
                        {{ __('Track milestones from enrollment through your current fund cycle.') }}
                    </p>
                </div>
            </div>

            @php
                $completed = collect($steps)->where('state', 'complete')->count();
                $total = count($steps);
                $pct = $total > 0 ? (int) round(($completed / $total) * 100) : 0;
            @endphp

            <div
                class="flex shrink-0 items-center gap-2 self-start rounded-full border border-emerald-200/90 bg-white/90 px-3 py-1.5 shadow-sm backdrop-blur-sm dark:border-emerald-500/30 dark:bg-gray-900/80"
                title="{{ trans_choice(':count milestone complete|:count milestones complete', $completed, ['count' => $completed]) }}"
            >
                <span class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">
                    {{ __('Progress') }}
                </span>
                <span class="text-xs font-bold tabular-nums text-gray-900 dark:text-white">
                    {{ $completed }}/{{ $total }}
                </span>
                <span
                    class="hidden h-1.5 w-16 overflow-hidden rounded-full bg-emerald-100 dark:bg-emerald-950 sm:block"
                    role="progressbar"
                    aria-valuenow="{{ $pct }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <span
                        class="block h-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 transition-all duration-500"
                        style="width: {{ $pct }}%"
                    ></span>
                </span>
            </div>
        </header>

        <div class="relative overflow-x-auto px-3 py-4 sm:px-4 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <x-member-lifecycle-stepper :steps="$steps" variant="journey" />
        </div>
    </section>
@endif
