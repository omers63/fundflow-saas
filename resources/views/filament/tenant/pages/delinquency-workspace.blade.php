<x-filament-panels::page>
    @php
$badges = $this->getTabBadges();
$memberLabel = $sideTab === 'overdue' ? $this->filteredMemberLabel() : null;
$showOverviewExtras = in_array($sideTab, ['overview', 'related'], true);
    @endphp

    <div class="ff-tenant-tab-pills mb-4 flex flex-wrap gap-2" wire:key="delinquency-tab-pills">
        @foreach ($this->getTabLabels() as $tab => $label)
                    @php
            $count = $badges[$tab] ?? 0;
                    @endphp
                    <a
                        href="{{ \App\Filament\Tenant\Support\DelinquencyTabRegistry::url($tab) }}"
                        wire:click.prevent="setSideTab(@js($tab))"
                        wire:key="delinquency-tab-{{ $tab }}"
                        @class([
                'ff-tenant-tab-pills__item no-underline',
                'ff-tenant-tab-pills__item--active' => $sideTab === $tab,
                'ff-tenant-tab-pills__item--danger' => $sideTab !== $tab && $tab === 'overdue' && $count > 0,
                'ff-tenant-tab-pills__item--warning' => $sideTab !== $tab && in_array($tab, ['guarantor', 'policy'], true) && $count > 0,
            ])
                    >
                        <x-ff-tab-pill-label :label="$label" :key="$tab" />
                        @if ($count > 0)
                            <span @class([
                    'ms-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums',
                    'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-200' => $sideTab === $tab,
                    'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200' => $sideTab !== $tab && $tab === 'overdue',
                    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $sideTab !== $tab && $tab !== 'overdue',
                ])>{{ $count }}</span>
                        @endif
                    </a>
        @endforeach
    </div>

    <div class="min-w-0 space-y-4" wire:key="delinquency-panel-{{ $sideTab }}">
        @if ($showOverviewExtras)
                    <x-ff-lazy-fold
                        section="insights"
                        :unfolded="$this->isSectionUnfolded('insights')"
                        :title="__('Delinquency insights')"
                        :hint="__('Expand to load KPIs and risk summary.')"
                    >
                        @php
    $snapshot = $this->insightsSnapshot();
                        @endphp
                        @include('filament.tenant.widgets.partials.insights-head', [
        'hero' => $snapshot['hero'] ?? null,
        'kpis' => $snapshot['kpis'] ?? null,
        'compact' => false,
    ])
                    </x-ff-lazy-fold>

                    <x-ff-lazy-fold
                        section="maintenance"
                        :unfolded="$this->isSectionUnfolded('maintenance')"
                        :title="__('Maintenance')"
                        :hint="__('Expand to load schedule and last run details.')"
                    >
                        @php
    $lastRun = $this->lastMaintenanceRun();
                        @endphp
                        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-slate-800">
                            <p class="text-sm text-gray-700 dark:text-gray-200">{{ $this->scheduleHint() }}</p>
                            @if ($lastRun)
                                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                                    {{ __('Last run :at — Overdue: :overdue · Policy breaches: :arrears · Clear: :cleared · Warnings: :warned · Guarantor debits: :debited', [
            'at' => \Illuminate\Support\Carbon::parse($lastRun['at'])->timezone(config('app.timezone'))->toDayDateTimeString(),
            'overdue' => $lastRun['result']['marked_overdue'] ?? 0,
            'arrears' => $lastRun['result']['delinquent_count'] ?? 0,
            'cleared' => $lastRun['result']['cleared_count'] ?? 0,
            'warned' => $lastRun['result']['warned'] ?? 0,
            'debited' => $lastRun['result']['debited_from_guarantor'] ?? 0,
        ]) }}
                                                </p>
                            @else
                                <p class="mt-2 text-sm text-gray-500">{{ __('No delinquency check has been run from this workspace yet.') }}</p>
                            @endif
                        </div>
                    </x-ff-lazy-fold>
        @endif

        @if ($sideTab === 'overview')
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
        ['tab' => 'overdue', 'label' => __('Review overdue installments'), 'description' => __('Flip pending EMIs to overdue and work the risk queue.')],
        ['tab' => 'guarantor', 'label' => __('Review guarantor exposure'), 'description' => __('Transfer or restore liability on loans past grace.')],
        ['tab' => 'policy', 'label' => __('Review policy breaches'), 'description' => __('Members exceeding consecutive or rolling miss thresholds.')],
        ['tab' => 'related', 'label' => __('Open related queues'), 'description' => __('Contribution arrears, members arrears, and settings.')],
    ] as $card)
                        <a
                            href="{{ \App\Filament\Tenant\Support\DelinquencyTabRegistry::url($card['tab']) }}"
                            class="rounded-xl border border-gray-200 bg-white p-4 text-start no-underline shadow-sm transition hover:border-sky-300 dark:border-white/10 dark:bg-slate-800 dark:hover:border-sky-700"
                        >
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $card['label'] }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ $card['description'] }}</p>
                        </a>
                @endforeach
            </div>

            <p class="text-sm text-gray-500">
                {{ __('Empty queues often mean installments were never marked overdue — not that nobody owes. Use Delinquency tools after a cycle deadline.') }}
            </p>
        @elseif ($sideTab === 'related')
            <x-ff-lazy-fold
                section="related_links"
                :unfolded="$this->isSectionUnfolded('related_links')"
                :title="__('Related queues')"
                :hint="__('Expand to load arrears and settings links.')"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ($this->relatedLinks() as $link)
                        <a
                            href="{{ $link['url'] }}"
                            class="rounded-xl border border-gray-200 bg-white p-4 no-underline shadow-sm transition hover:border-sky-300 dark:border-white/10 dark:bg-slate-800 dark:hover:border-sky-700"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $link['label'] }}</p>
                                @if (filled($link['badge']))
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold tabular-nums text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                        {{ $link['badge'] }}
                                    </span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-gray-500">{{ $link['description'] }}</p>
                        </a>
                    @endforeach
                </div>
            </x-ff-lazy-fold>
        @else
            @if ($sideTab === 'overdue' && filled($memberLabel))
                <div class="flex flex-wrap items-center gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100">
                    <span>{{ __('Filtered to :name', ['name' => $memberLabel]) }}</span>
                    <button type="button" wire:click="clearMemberFilter" class="font-semibold underline">
                        {{ __('Clear filter') }}
                    </button>
                </div>
            @endif

            <div wire:key="delinquency-table-{{ $sideTab }}">
                {{ $this->table }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
