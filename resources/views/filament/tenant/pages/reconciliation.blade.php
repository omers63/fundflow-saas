<x-filament-panels::page>
    <div class="lg:grid lg:grid-cols-[minmax(0,14rem)_1fr] lg:gap-8">
        {{-- Sidebar --}}
        <aside class="mb-6 space-y-1 lg:mb-0">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Workspace') }}</p>
            <button type="button" wire:click="setSideTab('overview')"
                @class([
                    'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium transition',
                    'bg-primary-600 text-white shadow-sm dark:bg-primary-500' => $sideTab === 'overview',
                    'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $sideTab !== 'overview',
                ])>
                <x-heroicon-o-chart-pie class="h-5 w-5 shrink-0" />
                {{ __('Overview') }}
            </button>
            <button type="button" wire:click="setSideTab('exceptions')"
                @class([
                    'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium transition',
                    'bg-primary-600 text-white shadow-sm dark:bg-primary-500' => $sideTab === 'exceptions',
                    'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $sideTab !== 'exceptions',
                ])>
                <x-heroicon-o-shield-exclamation class="h-5 w-5 shrink-0" />
                <span class="flex min-w-0 flex-1 items-center justify-between gap-2">
                    <span>{{ __('Exceptions') }}</span>
                    @php($openCount = $this->getOpenExceptionCount())
                    @if ($openCount > 0)
                        <span class="inline-flex min-w-[1.25rem] justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $openCount }}</span>
                    @endif
                </span>
            </button>
            <button type="button" wire:click="setSideTab('snapshots')"
                @class([
                    'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium transition',
                    'bg-primary-600 text-white shadow-sm dark:bg-primary-500' => $sideTab === 'snapshots',
                    'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $sideTab !== 'snapshots',
                ])>
                <x-heroicon-o-clipboard-document-list class="h-5 w-5 shrink-0" />
                {{ __('Snapshots') }}
            </button>
            <button type="button" wire:click="setSideTab('methodology')"
                @class([
                    'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium transition',
                    'bg-primary-600 text-white shadow-sm dark:bg-primary-500' => $sideTab === 'methodology',
                    'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $sideTab !== 'methodology',
                ])>
                <x-heroicon-o-document-text class="h-5 w-5 shrink-0" />
                {{ __('Methodology') }}
            </button>
        </aside>

        <div class="min-w-0 space-y-6">
            @if ($sideTab === 'overview')
                <section class="overflow-hidden rounded-2xl bg-gradient-to-r from-slate-800 to-slate-900 px-6 py-6 text-white shadow-md ring-1 ring-white/10 dark:from-slate-900 dark:to-black">
                    <p class="text-xs uppercase tracking-[0.16em] text-white/70">{{ __('Finance control') }}</p>
                    <h2 class="mt-1 text-2xl font-semibold">{{ __('Reconciliation control center') }}</h2>
                    <p class="mt-2 max-w-3xl text-sm text-white/85">
                        {{ __('Run checks on demand or rely on scheduled daily and monthly snapshots. Critical failures mean stored balances disagree with the ledger — investigate before period close.') }}
                    </p>
                </section>

                @php($latest = $this->getLatestSnapshots()->first())
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Latest snapshot') }}</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">
                            {{ $latest ? '#' . $latest->id : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $latest?->as_of?->diffForHumans() ?? __('Run from header actions') }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Latest verdict') }}</p>
                        <p class="mt-1 text-lg font-semibold {{ $latest?->is_passing ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $latest ? ($latest->is_passing ? __('Pass') : __('Fail')) : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            @if ($latest)
                                {{ __('Critical') }} {{ $latest->critical_issues }} · {{ __('Warnings') }} {{ $latest->warnings }}
                            @else
                                {{ __('No data yet') }}
                            @endif
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Unposted bank (now)') }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">
                            {{ $latest ? number_format($latest->summary['pipeline']['bank_unposted_count'] ?? 0) : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Rows awaiting cash post') }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Open exceptions') }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums {{ $this->getOpenExceptionCount() > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white' }}">
                            {{ number_format($this->getOpenExceptionCount()) }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            @if ($this->getOpenExceptionCount() > 0)
                                <button type="button" wire:click="setSideTab('exceptions')" class="font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ __('Review queue') }}</button>
                            @else
                                {{ __('Operational queue clear') }}
                            @endif
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Ledger mismatches') }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">
                            {{ $latest ? number_format($latest->report['checks']['ledger_balances']['mismatch_count'] ?? 0) : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Accounts out of balance') }}</p>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('How to use') }}</h3>
                    <ul class="mt-3 list-inside list-disc space-y-1 text-sm text-gray-600 dark:text-gray-400">
                        <li>{{ __('Use') }} <strong>{{ __('Run now (real-time)') }}</strong> {{ __('before sensitive operations or after bulk imports.') }}</li>
                        <li><strong>{{ __('Daily') }}</strong> {{ __('and') }} <strong>{{ __('monthly') }}</strong> {{ __('snapshots tag the reporting window for audit; full ledger checks always use the current database state.') }}</li>
                        <li>{{ __('Open') }} <strong>{{ __('Exceptions') }}</strong> {{ __('for the nightly control queue — resolve, defer, or run the batch from header actions.') }}</li>
                        <li>{{ __('Open') }} <strong>{{ __('Snapshots') }}</strong> {{ __('to inspect history; download') }} <strong>JSON</strong> {{ __('(full machine-readable) or') }} <strong>PDF</strong> {{ __('(human-readable summary, truncated payload).') }}</li>
                        <li>{{ __('Optional') }} <strong>{{ __('statement balance') }}</strong> {{ __('on each run compares master cash (book) to your declared closing balance; scheduled runs read') }} <code class="text-xs">reconciliation.bank_statement_balance</code> {{ __('and') }} <code class="text-xs">reconciliation.bank_statement_date</code> {{ __('from settings.') }}</li>
                    </ul>
                </div>
            @elseif ($sideTab === 'exceptions')
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                    <div class="mb-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Reconciliation exceptions') }}</h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Operational issues raised by the nightly batch or realtime checks. Resolve individually or run the batch again from header actions.') }}</p>
                    </div>
                    <div wire:key="reconciliation-exceptions-table">
                        {{ $this->table }}
                    </div>
                </div>
            @elseif ($sideTab === 'snapshots')
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                    <div class="border-b border-gray-100 px-5 py-4 dark:border-white/10">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Stored snapshots') }}</h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Newest first. Select a row to preview summary and download the complete machine-readable report.') }}</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[40rem] text-left text-sm">
                            <thead class="border-b border-gray-100 bg-gray-50/80 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-3">#</th>
                                    <th class="px-4 py-3">{{ __('Mode') }}</th>
                                    <th class="px-4 py-3">{{ __('As of') }}</th>
                                    <th class="px-4 py-3">{{ __('Verdict') }}</th>
                                    <th class="px-4 py-3">{{ __('Critical') }}</th>
                                    <th class="px-4 py-3">{{ __('Warnings') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                @forelse ($this->getLatestSnapshots() as $snap)
                                    <tr @class(['bg-primary-50/50 dark:bg-primary-500/10' => (int) $selectedSnapshotId === (int) $snap->id])>
                                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $snap->id }}</td>
                                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $snap->mode }}</td>
                                        <td class="px-4 py-3 tabular-nums text-gray-600 dark:text-gray-400">{{ $snap->as_of->format('Y-m-d H:i') }}</td>
                                        <td class="px-4 py-3">
                                            <span @class([
                                                'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200' => $snap->is_passing,
                                                'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200' => !$snap->is_passing,
                                            ])>{{ $snap->is_passing ? __('Pass') : __('Fail') }}</span>
                                        </td>
                                        <td class="px-4 py-3 tabular-nums text-gray-700 dark:text-gray-300">{{ $snap->critical_issues }}</td>
                                        <td class="px-4 py-3 tabular-nums text-gray-700 dark:text-gray-300">{{ $snap->warnings }}</td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <button type="button" wire:click="selectSnapshot({{ (int) $snap->id }})"
                                                class="text-primary-600 text-xs font-semibold hover:underline dark:text-primary-400">{{ __('View') }}</button>
                                            @if ($this->canExportDownloads())
                                                <span class="text-gray-300 dark:text-gray-600">|</span>
                                                <button type="button" wire:click="downloadReport({{ (int) $snap->id }})"
                                                    class="text-primary-600 text-xs font-semibold hover:underline dark:text-primary-400">JSON</button>
                                                <span class="text-gray-300 dark:text-gray-600">|</span>
                                                <button type="button" wire:click="downloadPdf({{ (int) $snap->id }})"
                                                    class="text-primary-600 text-xs font-semibold hover:underline dark:text-primary-400">PDF</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('No snapshots yet. Run reconciliation from the header.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @php($sel = $this->getSelectedSnapshot())
                @if ($sel)
                    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Snapshot #:id — summary', ['id' => $sel->id]) }}</h3>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Mode') }} {{ $sel->mode }} · {{ __('as of') }} {{ $sel->as_of->toIso8601String() }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($this->canExportDownloads())
                                    <button type="button" wire:click="downloadReport({{ (int) $sel->id }})"
                                        class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-800 shadow-sm hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:text-white dark:hover:bg-white/5">
                                        <x-heroicon-o-arrow-down-tray class="h-4 w-4" />
                                        JSON
                                    </button>
                                    <button type="button" wire:click="downloadPdf({{ (int) $sel->id }})"
                                        class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-800 shadow-sm hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:text-white dark:hover:bg-white/5">
                                        <x-heroicon-o-document-text class="h-4 w-4" />
                                        PDF
                                    </button>
                                @endif
                            </div>
                        </div>
                        <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach (($sel->summary['headline_checks'] ?? []) as $key => $severity)
                                <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-white/10">
                                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ str_replace('_', ' ', $key) }}</dt>
                                    <dd class="mt-0.5 text-sm font-semibold capitalize text-gray-900 dark:text-white">{{ $severity }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        @if (!empty($sel->report['checks']['ledger_balances']['mismatches']))
                            <div class="mt-4 rounded-lg border border-red-200 bg-red-50/80 p-3 text-xs text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
                                <p class="font-semibold">{{ __('Ledger mismatches (first rows)') }}</p>
                                <ul class="mt-2 list-inside list-disc space-y-1">
                                    @foreach (array_slice($sel->report['checks']['ledger_balances']['mismatches'], 0, 8) as $row)
                                        <li>{{ $row['name'] ?? __('Account #:id', ['id' => ($row['account_id'] ?? '')]) }} — Δ {{ number_format($row['delta'] ?? 0, 2) }} SAR</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @endif
            @else
                <div class="prose prose-sm max-w-none dark:prose-invert">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Reconciliation approach') }}</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('The engine treats') }} <strong>{{ __('ledger roll-forward') }}</strong> {{ __('as the source of truth: every account’s stored balance must equal the net of its') }} <code>transactions</code> {{ __('(credits minus debits). That catches partial reversals, manual edits, and import errors early.') }}
                    </p>
                    <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Two complementary layers') }}</h4>
                    <ul class="text-sm text-gray-600 dark:text-gray-400">
                        <li><strong>{{ __('Audit snapshots') }}</strong> — {{ __('this page stores historical reports (realtime / daily / monthly) with full check payloads for period close and external audit.') }}</li>
                        <li><strong>{{ __('Exception queue') }}</strong> — {{ __('the Exceptions tab is refreshed nightly by') }} <code class="text-xs">fund:nightly-reconciliation</code> {{ __('with auto-resolve and admin actions.') }}</li>
                    </ul>
                    <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Checks') }}</h4>
                    <ul class="text-sm text-gray-600 dark:text-gray-400">
                        <li><strong>{{ __('Stored balance vs ledger') }}</strong> — {{ __('critical if any account drifts beyond tolerance.') }}</li>
                        <li><strong>{{ __('Global trial') }}</strong> — {{ __('Σ credits vs Σ debits across all lines; warning if unequal (expected under some one-sided flows).') }}</li>
                        <li><strong>{{ __('Master vs Σ(member) cash & fund') }}</strong> — {{ __('advisory; member-only cash debits (repayments) and guarantor fund debits break strict parity by design.') }}</li>
                        <li><strong>{{ __('Bank statement vs book') }}</strong> — {{ __('optional: compare') }} <code class="text-xs">master_cash</code> {{ __('balance to a declared statement closing balance (UI or settings). Variance is warning by default, or critical if you toggle strict on the run.') }}</li>
                        <li><strong>{{ __('Contributions vs fund ledger') }}</strong> — {{ __('critical if any non-deleted contribution has no ledger lines; warning if Σ contribution amounts ≠ Σ master-fund credits sourced from contributions.') }}</li>
                        <li><strong>{{ __('Active loans') }}</strong> — {{ __('pending installment total vs loan account outstanding.') }}</li>
                        <li><strong>{{ __('Approved loans (with disbursement)') }}</strong> — {{ __('before installments exist, loan ledger should match') }} <code class="text-xs">amount_disbursed</code>; {{ __('with installments, compared to remaining schedule.') }}</li>
                        <li><strong>{{ __('Orphan loan accounts') }}</strong> — {{ __('critical if a loan-type account exists without a loan row.') }}</li>
                        <li><strong>{{ __('Import pipeline') }}</strong> — {{ __('counts imported (unmirrored) and uncleared bank rows (warnings if backlog). SMS import is not used in SaaS.') }}</li>
                        <li><strong>{{ __('Period metrics') }}</strong> — {{ __('for daily/monthly modes, counts ledger lines and posted imports in the selected window.') }}</li>
                    </ul>
                    <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Automation & settings') }}</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Scheduler runs') }} <code class="text-xs">fund:reconcile --daily</code> {{ __('at 06:20,') }} <code class="text-xs">fund:nightly-reconciliation</code> {{ __('at 06:30, and') }} <code class="text-xs">fund:reconcile --monthly</code> {{ __('on the 2nd at 06:30. Bank vs book settings are under System → Settings → General → Reconciliation.') }}
                    </p>
                    <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Access') }}</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('All tenant admins can view snapshots. Run and export actions require the admin flag on the tenant user.') }}
                    </p>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
