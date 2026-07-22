<x-filament-panels::page>
    <section
        class="rounded-2xl border border-gray-200 bg-white px-5 py-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <header class="mb-4 border-b border-gray-100 pb-4 dark:border-white/10">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Reconciliation') }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $this->getSubheading() }}
            </p>
        </header>

        @include('filament.tenant.partials.reconciliation-workspace-actions', [
    'class' => 'ff-audit-workspace-actions ff-recon-workspace-actions mb-4',
])

        @include('filament.tenant.partials.reconciliation-tab-pills')

        <div class="min-w-0 space-y-6" wire:key="reconciliation-workspace-{{ $this->sideTab }}-{{ (int) $this->advancedUi }}">
            @php($criticalCount = \App\Models\Tenant\ReconciliationException::query()->open()->where('severity', 'critical')->count())
            @if ($criticalCount > 0)
            <div role="alert" class="flex items-start gap-3 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-red-900 shadow-sm dark:border-red-700/60 dark:bg-red-950/40 dark:text-red-200">
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
                @include('filament.tenant.partials.reconciliation.next-steps')

                <div
                    class="rounded-xl border border-dashed border-gray-200 bg-gray-50/80 p-4 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                    {{ __('Open an issue row for context, links, and resolution actions. Use Run check now to refresh the fund status.') }}
                </div>
            @elseif ($this->sideTab === 'history')
                <div
                    class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                    <div class="mb-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ __('Reconciliation history') }}</h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Resolved exceptions with resolution notes. The nightly batch clears the queue and re-raises fresh issues.') }}
                        </p>
                        @php($lastBatch = $this->getLastNightlyBatch())
                        @if ($lastBatch)
                            <p class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900 dark:border-emerald-800/40 dark:bg-emerald-950/30 dark:text-emerald-200">
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
                            {{ __('Select a row to analyze context, suggested fixes, and resolution actions.') }}
                        </p>
                    </div>

                    @include('filament.tenant.partials.reconciliation-queue-insights')

                    {{ $this->table }}

                    @php($selectedException = $this->getSelectedException())
                            @if ($selectedException)
                                <div class="mt-5 border-t border-gray-100 pt-5 dark:border-white/10" wire:key="recon-exception-analysis-{{ $selectedException->id }}">
                                    <div class="mb-4">
                                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Issue analysis') }}</h3>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('Review summary, related records, and run fix actions without leaving the queue.') }}
                                        </p>
                                    </div>
                                    @include('filament.tenant.partials.reconciliation.exception-detail', ['exception' => $selectedException])
                                </div>
                            @elseif ($this->getOpenExceptionQueueStats()['total'] === 0)
                                <div class="mt-4 rounded-xl border border-dashed border-gray-200 bg-gray-50/80 px-4 py-6 text-center text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                                    {{ __('No open reconciliation exceptions. Run a check or wait for the nightly batch to refresh the queue.') }}
                                </div>
                            @endif
                        </div>
                    @elseif ($this->sideTab === 'snapshots' && $this->advancedUi)
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-white/10">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Stored snapshots') }}</h3>
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
                                        <td         class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $snap->id }}</td>
                                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                                    {{ \App\Support\Reconciliation\ReconciliationSnapshotPresenter::modeLabel($snap->mode) }}
                                        </td        >

                                                                                            <td class="px-4 py-3 tabular-nums text-gray-600 dark:text-gray-400">
                                                    {{ $snap->as_of->format('Y-m-d H:i') }}</td>
                                        <td class="px-4 py-3">
                                 <spa
                                                       n @class([
                                                        'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200' => $snap->is_passing,
                                                        'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200' => !$snap->is_passing,
                                                    ])>{{ $snap->is_passing ? __('Pass') : __('Fail') }}</span>
                                        </td        >

                                                                                            <td class="px-4 py-3 tabular-nums text-gray-700 dark:text-gray-300">
                                                    {{ $snap->critical_issues }}</td>
                                                <td class="px-4 py-3 tabular-nums text-gray-700 dark:text-gray-300">
                                                    {{ $snap->warnings }}</td>
                                                <td class="px-4 py-3 text-end whitespace-nowrap">
                                                    <button type="button" wire:click="selectSnapshot({{ (int) $snap->id }})"
                                                    class="text-sky-600 text-xs font-semibold hover:underline dark:text-sky-400">{{ __('View') }}</button>
                                                @if ($this->canExportDownloads())
                                                    <span class="text-gray-300 dark:text-gray-600">|</span>
                                                    <button type="button" wire:click="downloadReport({{ (int) $snap->id }})"
                                                        class="text-sky-600 text-xs font-semibold hover:underline dark:text-sky-400">{{ __('JSON') }}</button>
                                                    <span class="text-gray-300 dark:text-gray-600">|</span>
                                                        <button type="button" wire:click="downloadPdf({{ (int) $snap->id }})"
                                                            class="text-sky-600 text-xs font-semibold hover:underline dark:text-sky-400">{{ __('PDF') }}</button>
                                                @endif
                                                @if ($this->canManageSnapshots())
                                                    <span class="text-gray-300 dark:text-gray-600">|</span>
                                                    <button type="button" wire:click="deleteSnapshot({{ (int) $snap->id }})"
                                                            wire:confirm="{{ __('Delete this reconciliation snapshot? This cannot be undone.') }}"
                                                            class="text-red-600 text-xs font-semibold hover:underline dark:text-red-400">{{ __('Delete') }}</button>
                                                @endif
                                        </td>
                                    </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $this->canManageSnapshots() ? 8 : 7 }}" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
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
                                        <div class="mb-4 flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 pb-4 dark:border-white/10">
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
                    <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50/80 px-4 py-6 text-center text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                            {{ __('Select a snapshot row above to analyze check results and mismatches.') }}
                            </div>
                @endif
            @elseif ($this->sideTab === 'methodology' && $this->advancedUi)
            <div class="prose prose-sm max-w-none dark:prose-invert">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Reconciliation approach') }}</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('The engine treats') }} <strong>{{ __('ledger roll-forward') }}</strong>
                    {{ __('as the source of truth: every account’s stored balance must equal the net of its') }}
                    <code>transactions</code>
                    {{ __('(credits minus debits). That catches partial reversals, manual edits, and import errors early.') }}
                </p>

                <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Two complementary layers') }}</h4>
                <ul class="text-sm text-gray-600 dark:text-gray-400">
                    <li><strong>{{ __('Audit snapshots') }}</strong> —
                        {{ __('this page stores historical reports (realtime / daily / monthly) with full check payloads for period close and external audit.') }}
                    </li>
                    <li><strong>{{ __('Exception queue') }}</strong> —
                        {{ __('the Exceptions tab is refreshed nightly by') }} <code class="text-xs">fund:nightly-reconciliation</code>
                        {{ __('with auto-resolve and admin actions.') }}</li>
                </ul>
                <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Checks') }}</h4>
                <ul class="text-sm text-gray-600 dark:text-gray-400">
                    <li><strong>{{ __('Stored balance vs ledger') }}</strong> —
                        {{ __('critical if any account drifts beyond tolerance.') }}</li>
                                <li><strong>{{ __('Global trial') }}</strong> —
                                    {{ __('Σ credits vs Σ debits may differ for same-direction pool or bank-import legs; warning only when unexpected unbalanced groups or unexpected null-reference lines remain.') }}
                                </li>
                                <li><strong>{{ __('Master vs Σ(member) cash & fund') }}</strong> —
                                    {{ __('advisory; member-only cash debits (repayments) and guarantor fund debits break strict parity by design.') }}
                                </li>
                                <li><strong>{{ __('Bank statement vs book') }}</strong> — {{ __('optional: compare') }} <code
                                        class="text-xs">master_cash</code>
                                    {{ __('balance to a declared statement closing balance (UI or settings). Variance is warning by default, or critical if you toggle strict on the run.') }}
                                </li>
                    <li><strong>{{ __('Contributions vs fund ledger') }}</strong> —
                        {{ __('critical if any non-deleted contribution has no ledger lines; warning if Σ contribution amounts ≠ Σ master-fund credits sourced from contributions.') }}
                    </li>
                    <li><strong>{{ __('Active loans') }}</strong> —
                                    {{ __('loan account outstanding vs expected ledger (scheduled pending EMIs minus partial repayments posted ahead of the schedule).') }}</li>
                    <li><strong>{{ __('Approved loans (with disbursement)') }}</strong> —
                        {{ __('before installments exist, loan ledger should match') }} <code
                            class="text-xs">amount_disbursed</code>;
                        {{ __('with installments, compared to ledger expected outstanding (scheduled minus partial paid).') }}</li>
                    <li><strong>{{ __('Orphan loan accounts') }}</strong> —
                        {{ __('critical if a loan-type account exists without a loan row.') }}</li>
                    <li><strong>{{ __('Import pipeline') }}</strong> —
                        {{ __('counts imported (unmirrored) and uncleared bank rows (warnings if backlog). SMS import is not used in SaaS.') }}
                    </li>
                    <li><strong>{{ __('Period metrics') }}</strong> —
                        {{ __('for daily/monthly modes, counts ledger lines and posted imports in the selected window.') }}
                    </li>
                </ul>
                <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Automation & settings') }}
                </h4>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Scheduler runs') }} <code class="text-xs">fund:reconcile --daily</code> {{ __('at 06:20,') }}
                    <code class="text-xs">fund:nightly-reconciliation</code> {{ __('at 06:30, and') }} <code
                        class="text-xs">fund:reconcile --monthly</code>
                    {{ __('on the 2nd at 06:30. Bank vs book settings are under System → Settings → General → Reconciliation.') }}
                </p>
                <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Access') }}</h4>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('All tenant admins can view snapshots. Run and export actions require the admin flag on the tenant user.') }}
                </p>
            </div>
            @endif
        </div>
    </section>

    @include('filament.tenant.partials.page-workspace-action-modals')
</x-filament-panels::page>