<x-filament-panels::page>
    <section
        class="rounded-2xl border border-gray-200 bg-white px-5 py-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <header class="mb-4 border-b border-gray-100 pb-4 dark:border-white/10">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Reconciliation') }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $this->getSubheading() }}
            </p>
        </header>

        @include('filament.tenant.partials.reconciliation-workspace-actions')

        @include('filament.tenant.partials.reconciliation-tab-pills')

        <div class="min-w-0 space-y-6" wire:key="reconciliation-workspace-{{ $this->sideTab }}">
            @if ($this->batchPostingIsHalted())
                <div role="alert"
                    class="flex flex-wrap items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900 shadow-sm dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0" />
                    <div class="min-w-0 flex-1 text-sm">
                        <p class="font-semibold">{{ __('Batch posting halted') }}</p>
                        <p class="mt-0.5 text-xs">
                            {{ $this->batchPostingHaltReason() ?? __('Critical reconciliation imbalance is blocking scheduled posting until resolved.') }}
                        </p>
                    </div>
                    @if (auth('tenant')->user()?->is_admin)
                        <button type="button" wire:click="clearBatchPostingHalt"
                            class="ff-tenant-btn shrink-0 border border-amber-400 bg-white px-3 py-1 text-xs font-semibold text-amber-900 hover:bg-amber-100 dark:border-amber-600 dark:bg-amber-950 dark:text-amber-100 dark:hover:bg-amber-900">
                            {{ __('Clear posting halt') }}
                        </button>
                    @endif
                    <button type="button" wire:click="setSideTab('exceptions')"
                        class="ff-tenant-btn shrink-0 px-3 py-1 text-xs">
                        {{ __('Review issues') }}
                    </button>
                </div>
            @endif

            @php($criticalCount = \App\Models\Tenant\ReconciliationException::query()->open()->where('severity', 'critical')->count())
            @if ($criticalCount > 0)
                <div role="alert"
                    class="flex items-start gap-3 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-red-900 shadow-sm dark:border-red-700/60 dark:bg-red-950/40 dark:text-red-200">
                    <x-heroicon-o-exclamation-circle class="mt-0.5 h-5 w-5 shrink-0 text-red-600 dark:text-red-400" />
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">
                            {{ trans_choice(':count critical exception open|:count critical exceptions open', $criticalCount, ['count' => $criticalCount]) }}
                        </p>
                        <p class="mt-0.5 text-xs text-red-700 dark:text-red-300">
                            {{ __('Ledger balances may be inconsistent. Review open issues and resolve before period close.') }}
                        </p>
                    </div>
                    <button type="button" wire:click="setSideTab('exceptions')"
                        class="ff-tenant-btn ff-tenant-btn--danger ms-auto shrink-0 px-3 py-1 text-xs">
                        {{ __('Review issues') }}
                    </button>
                </div>
            @endif

            @if ($this->sideTab === 'overview')
                @include('filament.tenant.partials.reconciliation-workspace-shortcuts')
                @include('filament.tenant.partials.reconciliation.health-cards')
                @include('filament.tenant.partials.reconciliation.settings-strip')
                @include('filament.tenant.partials.reconciliation.next-steps')

                <div
                    class="rounded-xl border border-dashed border-gray-200 bg-gray-50/80 p-4 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                    {{ __('Open an issue row for context and fix actions. Use Run check now for a realtime snapshot, or Exception queue re-check / Daily / Monthly for the other background runs.') }}
                </div>
            @elseif ($this->sideTab === 'history')
                <div
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                    <div class="mb-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ __('Reconciliation history') }}</h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Resolved exceptions with resolution notes. The nightly batch (06:30) clears the queue and re-raises fresh issues.') }}
                        </p>
                        @php($lastBatch = $this->getLastNightlyBatch())
                        @if ($lastBatch)
                            <p
                                class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900 dark:border-emerald-800/40 dark:bg-emerald-950/30 dark:text-emerald-200">
                                {{ __('Last batch (:time): raised :raised, auto-resolved :resolved, critical :critical', [
            'time' => $lastBatch->occurred_at?->format('d M Y H:i') ?? '—',
            'raised' => $lastBatch->payload['raised'] ?? 0,
            'resolved' => $lastBatch->payload['resolved'] ?? 0,
            'critical' => $lastBatch->payload['critical'] ?? 0,
        ]) }}
                            </p>
                        @endif
                    </div>
                    {{ $this->table }}
                </div>
            @elseif ($this->sideTab === 'exceptions')
                <div
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                    <div class="mb-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ __('Open issues') }}</h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Select a row to analyze context, suggested fixes, and resolution actions. Re-check the queue from More runs when you need a fresh scan before 06:30.') }}
                        </p>
                    </div>

                    @include('filament.tenant.partials.reconciliation-queue-insights')

                    {{ $this->table }}

                    @php($selectedException = $this->getSelectedException())
                    @if ($selectedException)
                        <div class="mt-5 border-t border-gray-100 pt-5 dark:border-white/10"
                            wire:key="recon-exception-analysis-{{ $selectedException->id }}">
                            <div class="mb-4">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Issue analysis') }}
                                </h3>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('Review summary, related records, and run fix actions without leaving the queue.') }}
                                </p>
                            </div>
                            @include('filament.tenant.partials.reconciliation.exception-detail', [
            'exception' => $selectedException,
        ])
                        </div>
                    @elseif ($this->getOpenExceptionQueueStats()['total'] === 0)
                        <div
                            class="mt-4 rounded-xl border border-dashed border-gray-200 bg-gray-50/80 px-4 py-6 text-center text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                            {{ __('No open reconciliation exceptions. Run a check or wait for the nightly batch to refresh the queue.') }}
                        </div>
                    @endif
                </div>
            @elseif ($this->sideTab === 'snapshots')
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                    <div class="border-b border-gray-100 px-5 py-4 dark:border-white/10">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Stored snapshots') }}
                                </h3>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('Newest first. Select a row to preview summary and download the complete machine-readable report.') }}
                                </p>
                            </div>
                            @if ($this->canManageSnapshots() && count($snapshotBulkSelection) > 0)
                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">
                                        {{ __(':count selected', ['count' => count($snapshotBulkSelection)]) }}
                                    </span>
                                    <x-filament::button color="danger" size="sm" outlined
                                        wire:click="deleteSelectedSnapshots"
                                        wire:confirm="{{ __('Delete the selected reconciliation snapshots? This cannot be undone.') }}">
                                        {{ __('Delete selected') }}
                                    </x-filament::button>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[40rem] text-start text-sm">
                            <thead
                                class="border-b border-gray-100 bg-gray-50/80 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                                <tr>
                                    @if ($this->canManageSnapshots())
                                        <th class="w-10 px-4 py-3">
                                            <input type="checkbox"
                                                class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-white/20 dark:bg-gray-900"
                                                wire:click="toggleAllSnapshotsForDeletion"
                                                @checked(count($snapshotBulkSelection) > 0 && count($snapshotBulkSelection) === $this->getLatestSnapshots()->count())
                                                aria-label="{{ __('Select all snapshots') }}" />
                                        </th>
                                    @endif
                                    <th class="px-4 py-3">#</th>
                                    <th class="px-4 py-3">{{ __('Mode') }}</th>
                                    <th class="px-4 py-3">{{ __('As of') }}</th>
                                    <th class="px-4 py-3">{{ __('Verdict') }}</th>
                                    <th class="px-4 py-3">{{ __('Critical') }}</th>
                                    <th class="px-4 py-3">{{ __('Warnings') }}</th>
                                    <th class="px-4 py-3 text-end">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                @forelse ($this->getLatestSnapshots() as $snap)
                                    <tr @class(['bg-primary-50/50 dark:bg-primary-500/10' => (int) $selectedSnapshotId === (int) $snap->id])>
                                        @if ($this->canManageSnapshots())
                                            <td class="px-4 py-3">
                                                <input type="checkbox"
                                                    class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-white/20 dark:bg-gray-900"
                                                    wire:model.live="snapshotBulkSelection"
                                                    value="{{ (int) $snap->id }}"
                                                    aria-label="{{ __('Select snapshot :id', ['id' => $snap->id]) }}" />
                                            </td>
                                        @endif
                                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $snap->id }}</td>
                                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                            {{ \App\Support\Reconciliation\ReconciliationSnapshotPresenter::modeLabel($snap->mode) }}
                                        </td>
                                        <td class="px-4 py-3 tabular-nums text-gray-600 dark:text-gray-400">
                                            {{ $snap->as_of->format('Y-m-d H:i') }}</td>
                                        <td class="px-4 py-3">
                                            <span @class([
            'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200' => $snap->is_passing,
            'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200' => !$snap->is_passing,
        ])>{{ $snap->is_passing ? __('Pass') : __('Fail') }}</span>
                                        </td>
                                        <td class="px-4 py-3 tabular-nums text-gray-700 dark:text-gray-300">
                                            {{ $snap->critical_issues }}</td>
                                        <td class="px-4 py-3 tabular-nums text-gray-700 dark:text-gray-300">
                                            {{ $snap->warnings }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-end">
                                            <button type="button" wire:click="selectSnapshot({{ (int) $snap->id }})"
                                                class="text-xs font-semibold text-sky-600 hover:underline dark:text-sky-400">{{ __('View') }}</button>
                                            @if ($this->canExportDownloads())
                                                <span class="text-gray-300 dark:text-gray-600">|</span>
                                                <button type="button" wire:click="downloadReport({{ (int) $snap->id }})"
                                                    class="text-xs font-semibold text-sky-600 hover:underline dark:text-sky-400">{{ __('JSON') }}</button>
                                                <span class="text-gray-300 dark:text-gray-600">|</span>
                                                <button type="button" wire:click="downloadPdf({{ (int) $snap->id }})"
                                                    class="text-xs font-semibold text-sky-600 hover:underline dark:text-sky-400">{{ __('PDF') }}</button>
                                            @endif
                                            @if ($this->canManageSnapshots())
                                                <span class="text-gray-300 dark:text-gray-600">|</span>
                                                <button type="button" wire:click="deleteSnapshot({{ (int) $snap->id }})"
                                                    wire:confirm="{{ __('Delete this reconciliation snapshot? This cannot be undone.') }}"
                                                    class="text-xs font-semibold text-red-600 hover:underline dark:text-red-400">{{ __('Delete') }}</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $this->canManageSnapshots() ? 8 : 7 }}"
                                            class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                            {{ __('No snapshots yet. Run reconciliation from the header.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @php($sel = $this->getSelectedSnapshot())
                @if ($sel)
                    <div
                        class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60 sm:p-5">
                        <div
                            class="mb-4 flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 pb-4 dark:border-white/10">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ __('Snapshot analysis') }}</h3>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('Review verdict, check details, and drill-down links. Export JSON or PDF for audit archives.') }}
                                </p>
                            </div>
                            @if ($this->canExportDownloads() || $this->canManageSnapshots())
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($this->canExportDownloads())
                                        <button type="button" wire:click="downloadReport({{ (int) $sel->id }})"
                                            class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-800 shadow-sm hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:text-white dark:hover:bg-white/5">
                                            <x-heroicon-o-arrow-down-tray class="h-4 w-4" />
                                            {{ __('JSON') }}
                                        </button>
                                        <button type="button" wire:click="downloadPdf({{ (int) $sel->id }})"
                                            class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-800 shadow-sm hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:text-white dark:hover:bg-white/5">
                                            <x-heroicon-o-document-text class="h-4 w-4" />
                                            {{ __('PDF') }}
                                        </button>
                                    @endif
                                    @if ($this->canManageSnapshots())
                                        <x-filament::button color="danger" size="sm" outlined
                                            wire:click="deleteSnapshot({{ (int) $sel->id }})"
                                            wire:confirm="{{ __('Delete this reconciliation snapshot? This cannot be undone.') }}">
                                            {{ __('Delete snapshot') }}
                                        </x-filament::button>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @include('filament.tenant.partials.reconciliation.snapshot-detail', ['snapshot' => $sel])
                    </div>
                @elseif ($this->getLatestSnapshots()->isNotEmpty())
                    <div
                        class="rounded-xl border border-dashed border-gray-200 bg-gray-50/80 px-4 py-6 text-center text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                        {{ __('Select a snapshot row above to analyze check results and mismatches.') }}
                    </div>
                @endif
            @elseif ($this->sideTab === 'methodology')
                @php($schedule = $this->getAutomationScheduleSummary())
                <div class="prose prose-sm max-w-none dark:prose-invert">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('How reconciliation works') }}
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Stored account balances must match the ledger of') }} <code>transactions</code>
                        {{ __('(credits minus debits). That catches partial reversals, manual edits, and import errors early.') }}
                    </p>

                    <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Two layers') }}</h4>
                    <ul class="text-sm text-gray-600 dark:text-gray-400">
                        <li><strong>{{ __('Snapshots') }}</strong> —
                            {{ __('this page stores historical reports (realtime / daily / monthly) with full check payloads for period close and audit.') }}
                        </li>
                        <li><strong>{{ __('Exception queue') }}</strong> —
                            {{ __('the Issues tab is refreshed nightly by') }}
                            <code class="text-xs">fund:nightly-reconciliation</code>
                            {{ __('with auto-resolve and admin actions. You can also re-check from More runs.') }}</li>
                    </ul>

                    <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Scheduled automation') }}
                    </h4>
                    <ul class="text-sm text-gray-600 dark:text-gray-400">
                        <li>{{ $schedule['invariants'] }}</li>
                        <li>{{ $schedule['daily'] }}</li>
                        <li>{{ $schedule['nightly'] }}</li>
                        <li>{{ $schedule['monthly'] }}</li>
                    </ul>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Declared bank balance, critical variance, digests, and match tolerances are under Settings → Reconciliation. The monthly day is under Settings → Collection (Automation).') }}
                    </p>

                    <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Key checks') }}</h4>
                    <ul class="text-sm text-gray-600 dark:text-gray-400">
                        <li><strong>{{ __('Stored balance vs ledger') }}</strong> —
                            {{ __('critical if any account drifts beyond tolerance.') }}</li>
                        <li><strong>{{ __('Master vs Σ(member) cash & fund') }}</strong> —
                            {{ __('pool-mirror invariants used by nightly batch and master assert.') }}</li>
                        <li><strong>{{ __('Bank statement vs book') }}</strong> —
                            {{ __('optional: compare master cash to the declared statement closing balance from Settings or the run modal.') }}
                        </li>
                        <li><strong>{{ __('Contributions, loans, and bank pipeline') }}</strong> —
                            {{ __('integrity of contribution/fund legs, loan schedules, and uncleared bank lines.') }}</li>
                    </ul>

                    <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Access') }}</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('All tenant users can view this workspace. Run checks, exports, and queue re-checks require an admin flag on the tenant user.') }}
                    </p>
                </div>
            @endif
        </div>
    </section>
</x-filament-panels::page>
