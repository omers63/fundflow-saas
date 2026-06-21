    <div class="ff-fiscal-close-workspace space-y-6">
        <section class="ff-maintenance-panel">
            <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Close workflow') }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Validate, snapshot, roll forward opening balances, and optionally purge Tier A ledger detail.') }}
                    </p>
                </div>
            </header>
            <div class="ff-maintenance-panel__body space-y-4">
                {{ $this->form }}

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-white/10">
                    <a href="{{ \App\Filament\Tenant\Pages\Settings::getUrl() }}"
                        class="text-sm font-medium text-sky-600 hover:underline dark:text-sky-400">
                        {{ __('Fiscal calendar settings') }}
                    </a>

                    @include('filament.tenant.partials.audit-system.workspace-actions', [
                        'names' => ['run_readiness'],
                    ])
                </div>
            </div>
        </section>

        @if ($readinessReport)
            @php
                $overallPass = (bool) ($readinessReport['can_proceed'] ?? false);
                $periodStart = \Carbon\Carbon::parse($readinessReport['period_start'] ?? now());
                $periodEnd = \Carbon\Carbon::parse($readinessReport['period_end'] ?? now());
                $assessedAt = \Carbon\Carbon::parse($readinessReport['assessed_at'] ?? now());
            @endphp

            <section class="ff-maintenance-panel">
                <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Readiness report') }}</h2>
                    </div>
                </header>
                <div class="ff-maintenance-panel__body space-y-4">
                    <div @class([
                        'rounded-xl border px-4 py-3 text-sm shadow-sm',
                        'border-emerald-200/80 bg-emerald-50/90 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-950/40 dark:text-emerald-100' => $overallPass,
                        'border-rose-200/80 bg-rose-50/90 text-rose-900 dark:border-rose-500/30 dark:bg-rose-950/40 dark:text-rose-100' => ! $overallPass,
                    ])>
                        <p class="font-semibold">
                            {{ $overallPass ? __('All required gates passed') : __('Close is not ready') }}
                        </p>
                        <p class="mt-1 text-xs leading-relaxed opacity-90">
                            {{ __('Fiscal year :label · Period :start – :end · Assessed :at', [
                                'label' => $readinessReport['fiscal_year_label'] ?? '—',
                                'start' => $periodStart->toFormattedDateString(),
                                'end' => $periodEnd->toFormattedDateString(),
                                'at' => $assessedAt->toDayDateTimeString(),
                            ]) }}
                        </p>
                    </div>

                    <div
                        class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        <table class="w-full text-sm">
                            <thead
                                class="border-b border-gray-200 bg-gray-50 text-start text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-3">{{ __('Check') }}</th>
                                    <th class="px-4 py-3">{{ __('Status') }}</th>
                                    <th class="px-4 py-3">{{ __('Summary') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach ($readinessReport['gates'] ?? [] as $gate)
                                    <tr wire:key="gate-{{ $gate['code'] ?? $loop->index }}">
                                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $gate['label'] ?? '—' }}</td>
                                        <td class="px-4 py-3">
                                            @php
                                                $badgeColor = match ($gate['status'] ?? 'fail') {
                                                    'pass' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
                                                    'warn' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
                                                    default => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
                                                };
                                            @endphp
                                            <span @class(['inline-flex rounded-full px-2 py-0.5 text-xs font-semibold uppercase', $badgeColor])>
                                                {{ $gate['status'] ?? 'fail' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $gate['message'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @include('filament.tenant.partials.audit-system.workspace-actions', [
                        'names' => ['build_snapshot'],
                        'class' => 'border-t border-gray-100 pt-4 dark:border-white/10',
                    ])
                </div>
            </section>
        @else
            <div
                class="rounded-xl border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
                {{ __('Run readiness checks to see whether this tenant can close books for the selected period.') }}
            </div>
        @endif

        @if ($activeClose)
            <section class="ff-maintenance-panel">
                <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Close record') }}</h2>
                </header>
                <div class="ff-maintenance-panel__body space-y-4 text-sm">
                    <p class="text-gray-600 dark:text-gray-300">
                        {{ __('Status: :status · Members: :count · Approved: :approved', [
                            'status' => $activeClose['status'] ?? '—',
                            'count' => $activeClose['member_count'] ?? 0,
                            'approved' => filled($activeClose['approved_at'] ?? null) ? \Carbon\Carbon::parse($activeClose['approved_at'])->toDayDateTimeString() : __('Not yet'),
                        ]) }}
                    </p>

                    @if (! empty($activeClose['export_manifest_json']['files']))
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('Archive exports') }}
                            </p>
                            <ul class="mt-2 space-y-1">
                                @foreach ($this->exportDownloadLinks() as $key => $link)
                                    <li>
                                        <a href="{{ $link['url'] }}"
                                            class="text-sm font-medium text-sky-600 hover:underline dark:text-sky-400"
                                            target="_blank" rel="noopener">
                                            {{ $link['label'] }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (! empty($activeClose['purge_summary_json']))
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            @if (! empty($activeClose['purge_summary_json']['tier_a']))
                                {{ __('Tier A — transactions: :tx · bank lines: :bank · resolved exceptions: :recon', [
                                    'tx' => $activeClose['purge_summary_json']['tier_a']['transactions'] ?? 0,
                                    'bank' => $activeClose['purge_summary_json']['tier_a']['bank_transactions'] ?? 0,
                                    'recon' => $activeClose['purge_summary_json']['tier_a']['reconciliation_exceptions'] ?? 0,
                                ]) }}
                            @else
                                {{ __('Purge summary — transactions: :tx · bank lines: :bank · resolved exceptions: :recon', [
                                    'tx' => $activeClose['purge_summary_json']['transactions'] ?? 0,
                                    'bank' => $activeClose['purge_summary_json']['bank_transactions'] ?? 0,
                                    'recon' => $activeClose['purge_summary_json']['reconciliation_exceptions'] ?? 0,
                                ]) }}
                            @endif
                        </p>
                        @if (! empty($activeClose['purge_summary_json']['tier_b']))
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Tier B — contributions: :contributions · installments: :installments · fund postings: :postings · audit rows: :audit', [
                                    'contributions' => $activeClose['purge_summary_json']['tier_b']['contributions'] ?? 0,
                                    'installments' => $activeClose['purge_summary_json']['tier_b']['loan_installments'] ?? 0,
                                    'postings' => $activeClose['purge_summary_json']['tier_b']['fund_postings'] ?? 0,
                                    'audit' => $activeClose['purge_summary_json']['tier_b']['fund_audit_log'] ?? 0,
                                ]) }}
                            </p>
                        @endif
                    @endif

                    @include('filament.tenant.partials.audit-system.workspace-actions', [
                        'names' => ['generate_exports', 'execute_roll_forward', 'execute_tier_a_purge', 'execute_tier_b_purge'],
                        'class' => 'border-t border-gray-100 pt-4 dark:border-white/10',
                    ])
                </div>
            </section>
        @endif
    </div>
