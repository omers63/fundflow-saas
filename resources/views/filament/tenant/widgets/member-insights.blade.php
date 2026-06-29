@php
    use App\Filament\Tenant\Resources\Members\MemberResource;

    $d = $this->getData();
    $pipeline = $d['pipeline'];
    $currency = $d['fund']['currency'];
    $hero = $d['needs_attention'] > 0
        ? [
            'title' => __('Members need your attention'),
            'subtitle' => trans_choice(':count inactive|:count inactive', $d['inactive'], ['count' => $d['inactive']])
                . ($d['delinquent'] > 0 ? ' · ' . trans_choice(':count with arrears|:count with arrears', $d['delinquent'], ['count' => $d['delinquent']]) : ''),
            'tone' => 'amber',
            'cta_url' => $pipeline['members_inactive_url'] ?? MemberResource::listTabUrl('inactive'),
            'cta_label' => __('Review inactive'),
        ]
        : [
            'title' => __('Roster healthy'),
            'subtitle' => __('No inactive members or arrears right now.'),
            'tone' => 'success',
        ];
@endphp
            
            <div class="ff-app-insights ff-members-list-insights w-full max-w-none space-y-3 mb-1">
                @include('filament.tenant.widgets.partials.insights-hero', ['hero' => $hero])
            
                <div class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                    <div
                        class="ff-members-roster-panel overflow-hidden rounded-2xl border border-gray-200/90 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                        <div class="grid grid-cols-2 divide-x divide-gray-100 dark:divide-gray-800 sm:grid-cols-4">
                            <a href="{{ $pipeline['members_active_url'] }}"
                                class="px-3 py-3 text-center transition hover:bg-emerald-50/60 dark:hover:bg-emerald-950/20">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Active') }}</p>
                                <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">
                                    {{ $d['active'] }}
                                </p>
                            </a>
                            <a href="{{ $pipeline['members_inactive_url'] ?? MemberResource::listTabUrl('inactive') }}"
                                class="px-3 py-3 text-center transition hover:bg-gray-50/80 dark:hover:bg-gray-800/60">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Inactive') }}
                                </p>
                                <p class="mt-1 text-2xl font-bold tabular-nums text-gray-700 dark:text-gray-200">
                                    {{ $d['inactive'] }}
                                </p>
                            </a>
                            <a href="{{ $pipeline['members_withdrawn_url'] ?? MemberResource::listTabUrl('withdrawn') }}"
                                class="px-3 py-3 text-center transition hover:bg-rose-50/60 dark:hover:bg-rose-950/20">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Withdrawn') }}
                                </p>
                                <p class="mt-1 text-2xl font-bold tabular-nums text-rose-600 dark:text-rose-400">
                                    {{ $d['withdrawn'] }}
                                </p>
                            </a>
                            <a href="{{ MemberResource::listTabUrl('migration_pending') }}"
                                class="px-3 py-3 text-center transition hover:bg-violet-50/60 dark:hover:bg-violet-950/20">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Migration') }}
                                </p>
                                <p class="mt-1 text-2xl font-bold tabular-nums text-violet-600 dark:text-violet-400">
                                    {{ MemberResource::migrationPendingCount() }}
                                </p>
                            </a>
                        </div>
            
                        <div class="border-t border-gray-100 px-3 py-2.5 dark:border-gray-800">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Roster snapshot') }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-600 dark:text-gray-300">
                                <span
                                    class="rounded-full bg-gray-100 px-2.5 py-1 dark:bg-gray-800">{{ __(':count total', ['count' => $d['total']]) }}</span>
                                <a href="{{ $pipeline['members_arrears_url'] ?? MemberResource::listTabUrl('delinquent') }}"
                                    class="rounded-full bg-amber-100 px-2.5 py-1 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">{{ __(':count with arrears', ['count' => $d['delinquent']]) }}</a>
                                <span
                                    class="rounded-full bg-gray-100 px-2.5 py-1 dark:bg-gray-800">{{ __(':count dependents', ['count' => $d['dependents']]) }}</span>
                                <span
                                    class="rounded-full bg-gray-100 px-2.5 py-1 dark:bg-gray-800">{{ __(':count on loans', ['count' => $d['with_active_loans']]) }}</span>
                                @if ($d['avg_contribution'] > 0)
                                    <span class="rounded-full bg-gray-100 px-2.5 py-1 dark:bg-gray-800">
                                        {{ __('Avg :amount/mo', ['amount' => number_format($d['avg_contribution'], 0)]) }}
                                    </span>
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
                                    {{ __('Needs attention') }}
                                </h4>
                            </div>
                            @if ($d['zero_cash_members'] > 0)
                                <span
                                    class="text-[10px] text-rose-600 dark:text-rose-400">{{ __(':count zero cash', ['count' => $d['zero_cash_members']]) }}</span>
                            @endif
                        </div>
                        <div class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($d['attention_queue'] as $member)
                                <a href="{{ $member['view_url'] }}"
                                    class="flex items-center gap-2 px-3 py-2.5 transition hover:bg-gray-50 dark:hover:bg-gray-800/80">
                                    <span
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-xs font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                        {{ strtoupper(substr($member['name'], 0, 1)) }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-gray-900 dark:text-white">
                                            <x-arabic-text :text="$member['name']" />
                                        </p>
                                        <p class="truncate text-[11px] text-gray-400">
                                            <x-member::amount :value="$member['contribution_amount']" :currency="$currency"
                                                :precision="0" />
                                        </p>
                                    </div>
                                    <span @class([
                                        'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold',
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $member['status_key'] === 'active' && !($member['has_arrears'] ?? false),
                                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $member['status_key'] === 'active' && ($member['has_arrears'] ?? false),
                                        'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200' => $member['status_key'] === 'inactive',
                                        'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200' => $member['status_key'] === 'withdrawn',
                                    ])>{{ $member['status'] }}</span>
                                </a>
                            @empty
                                <div class="px-3 py-8 text-center">
                                    <x-heroicon-o-check-circle class="mx-auto h-7 w-7 text-emerald-400" />
                                    <p class="mt-2 text-sm text-gray-500">{{ __('Everyone is in good standing') }}</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>