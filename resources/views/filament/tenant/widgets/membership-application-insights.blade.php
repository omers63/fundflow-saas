@php
    $d = $this->getData();
    $pipeline = $d['pipeline'];
    $fees = $d['fees'];
    $maxType = max(1, collect($d['type_breakdown'])->max('count'));
    $currency = $fees['currency'];

    $hero = $d['pending'] > 0
        ? [
            'title' => __('Applications need your attention'),
            'subtitle' => trans_choice(':count pending', $d['pending'], ['count' => $d['pending']])
                . ($d['pending_over_sla'] > 0 ? ' · ' . trans_choice(':count past SLA', $d['pending_over_sla'], ['count' => $d['pending_over_sla']]) : ''),
            'tone' => 'amber',
            'cta_url' => $pipeline['applications_pending_url'],
            'cta_label' => __('Review pending'),
        ]
        : [
            'title' => __('Inbox clear'),
            'subtitle' => __('No applications awaiting review'),
            'tone' => 'success',
        ];
@endphp

<div class="ff-app-insights ff-applications-list-insights w-full max-w-none space-y-3 mb-1" @if (filled($pollingInterval ?? null)) wire:poll.{{ $pollingInterval }} @endif>
    @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $hero])

    <div class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <div
            class="overflow-hidden rounded-2xl border border-gray-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="grid grid-cols-1 divide-y divide-gray-100 dark:divide-gray-800 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                <a href="{{ $pipeline['applications_pending_url'] }}"
                    class="px-3 py-3 text-center transition hover:bg-amber-50/60 dark:hover:bg-amber-950/20">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Pending') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-amber-600 dark:text-amber-400">
                        {{ $d['pending'] }}</p>
                </a>
                <a href="{{ $pipeline['applications_approved_url'] }}"
                    class="px-3 py-3 text-center transition hover:bg-emerald-50/60 dark:hover:bg-emerald-950/20">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Approved') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">
                        {{ $d['approved'] }}</p>
                </a>
                <a href="{{ $pipeline['members_url'] }}"
                    class="px-3 py-3 text-center transition hover:bg-sky-50/60 dark:hover:bg-sky-950/20">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Members') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-sky-600 dark:text-sky-400">
                        {{ $pipeline['active_members'] }}</p>
                </a>
            </div>
            <div class="border-t border-gray-100 px-3 py-2.5 dark:border-gray-800">
                <div class="flex flex-wrap gap-2 text-xs text-gray-600 dark:text-gray-300">
                    <span
                        class="rounded-full bg-gray-100 px-2.5 py-1 dark:bg-gray-800">{{ __(':count new this month', ['count' => $d['new_this_month']]) }}</span>
                    @if ($d['approval_rate'] !== null)
                        <span
                            class="rounded-full bg-gray-100 px-2.5 py-1 dark:bg-gray-800">{{ __(':rate% approval rate', ['rate' => $d['approval_rate']]) }}</span>
                    @endif
                    @if ($d['avg_review_days'] > 0)
                        <span
                            class="rounded-full bg-gray-100 px-2.5 py-1 dark:bg-gray-800">{{ __(':days d avg review', ['days' => $d['avg_review_days']]) }}</span>
                    @endif
                </div>
            </div>
        </div>

        <div
            class="overflow-hidden rounded-2xl border border-gray-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div
                class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-800">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-o-queue-list class="h-4 w-4 text-amber-500" />
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ __('Review queue') }}</h4>
                </div>
                @if ($d['pending_over_sla'] > 0)
                    <span
                        class="text-[10px] font-semibold uppercase text-rose-600 dark:text-rose-400">{{ __('SLA') }}</span>
                @endif
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($d['oldest_pending'] as $app)
                    <a href="{{ $app['edit_url'] }}"
                        class="flex items-center gap-2 px-3 py-2.5 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                        <span
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-xs font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                            {{ strtoupper(substr($app['name'], 0, 1)) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $app['name'] }}</p>
                            <p class="truncate text-[11px] text-gray-400">{{ ucfirst($app['type']) }} ·
                                {{ $app['has_receipt'] ? __('Receipt') : __('No receipt') }}</p>
                        </div>
                        <span @class([
                            'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                            'bg-amber-100 text-amber-800 dark:bg-amber-900/40' => $app['days_waiting'] <= 7,
                            'bg-rose-100 text-rose-800 dark:bg-rose-900/40' => $app['days_waiting'] > 7,
                        ])>{{ $app['days_waiting'] }}d</span>
                    </a>
                @empty
                    <div class="px-3 py-8 text-center">
                        <x-heroicon-o-check-circle class="mx-auto h-7 w-7 text-emerald-400" />
                        <p class="mt-2 text-sm text-gray-500">{{ __('Queue is empty') }}</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>