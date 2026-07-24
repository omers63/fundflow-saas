<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Support\MoneyDisplay;
use App\Filament\Tenant\Concerns\EmbedsAsAuditWorkspacePanel;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\ReconciliationExceptions\Tables\ReconciliationExceptionsTable;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Filament\Tenant\Support\ReconciliationTabRegistry;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Jobs\Tenant\RunReconciliationJob;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Services\BankClearingMatchService;
use App\Services\ReconciliationPdfService;
use App\Services\ReconciliationReportService;
use App\Support\AutomationScheduleSettings;
use App\Support\BatchPostingGate;
use App\Support\ContributionPolicySettings;
use App\Support\Reconciliation\ReconciliationHealthSummary;
use BackedEnum;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ReconciliationOverviewPage extends Page implements HasTable
{
    use EmbedsAsAuditWorkspacePanel;
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Reconciliation';

    protected static ?string $slug = 'reconciliation';

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_ACCOUNTS;

    protected static ?int $navigationSort = TenantNavigation::SORT_RECONCILIATION;

    protected string $view = 'filament.tenant.pages.reconciliation';

    /** @var 'overview'|'exceptions'|'history'|'snapshots'|'methodology' */
    #[Url(as: 'sideTab')]
    public string $sideTab = 'overview';

    public ?int $selectedSnapshotId = null;

    /** @var list<int> */
    public array $snapshotBulkSelection = [];

    #[Url(as: 'exception')]
    public ?int $selectedExceptionId = null;

    #[Url(as: 'queueDomain')]
    public ?string $queueDomainFilter = null;

    public ?string $reconciliationRunFeedback = null;

    public ?string $reconciliationRunToken = null;

    public ?int $reconciliationRunQueuedAt = null;

    public static function canAccess(): bool
    {
        return auth('tenant')->check();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Schema::hasTable('reconciliation_exceptions')
            || Schema::hasTable('reconciliation_snapshots');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! Schema::hasTable('reconciliation_exceptions')) {
            return null;
        }

        try {
            $count = ReconciliationException::query()->open()->count();

            return $count > 0 ? (string) $count : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getTitle(): string
    {
        return __('Reconciliation');
    }

    public function getSubheading(): ?string
    {
        return __('Check fund balance, resolve open issues, and review stored snapshots. Scheduled runs use Settings → Reconciliation.');
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fi-page-reconciliation'];
    }

    /**
     * @return array<string, string>
     */
    public function getReconciliationTabs(): array
    {
        return ReconciliationTabRegistry::tabs();
    }

    public function mount(): void
    {
        $this->normalizeSideTab();
        $this->ensureSnapshotSelected();
        $this->ensureExceptionSelected();
        $this->refreshWorkspacePanelActions();
    }

    protected function ensureSnapshotSelected(): void
    {
        if ($this->sideTab !== 'snapshots' || $this->selectedSnapshotId !== null) {
            return;
        }

        $latestId = ReconciliationSnapshot::query()->latest('as_of')->value('id');

        if (is_numeric($latestId)) {
            $this->selectedSnapshotId = (int) $latestId;
        }
    }

    /**
     * @return array{
     *     bank_balance_label: string,
     *     bank_date_label: string,
     *     bank_critical_label: string,
     *     tolerance_label: string,
     *     month_boundary_label: string
     * }
     */
    public function getReconciliationSettingsSummary(): array
    {
        $bank = ReconciliationReportService::bankOptionsFromSettings();
        $balance = $bank['declared_bank_balance'] ?? null;
        $date = $bank['declared_bank_date'] ?? null;
        $critical = (bool) ($bank['bank_mismatch_treat_as_critical'] ?? false);
        $tolerance = ContributionPolicySettings::reconTolerance();
        $monthDay = AutomationScheduleSettings::monthBoundaryDay();

        return [
            'bank_balance_label' => is_numeric($balance)
                ? (MoneyDisplay::format((float) $balance) ?? (string) $balance)
                : __('Not set'),
            'bank_date_label' => filled($date)
                ? __('As of :date', ['date' => $date])
                : __('No statement date saved'),
            'bank_critical_label' => $critical
                ? __('Treated as critical on scheduled runs')
                : __('Warning only (default)'),
            'tolerance_label' => MoneyDisplay::format($tolerance) ?? number_format($tolerance, 2),
            'month_boundary_label' => __('Day :day', ['day' => $monthDay]),
        ];
    }

    public function batchPostingIsHalted(): bool
    {
        return app(BatchPostingGate::class)->isHalted();
    }

    public function batchPostingHaltReason(): ?string
    {
        return app(BatchPostingGate::class)->reason();
    }

    public function clearBatchPostingHalt(): void
    {
        abort_unless(auth('tenant')->user()?->is_admin === true, 403);

        app(BatchPostingGate::class)->clear();

        Notification::make()
            ->title(__('Batch posting halt cleared'))
            ->success()
            ->send();
    }

    /**
     * @return array<string, string>
     */
    public function getAutomationScheduleSummary(): array
    {
        $monthDay = AutomationScheduleSettings::monthBoundaryDay();

        return [
            'invariants' => __('Daily at :time — assert master cash/fund vs member sums', [
                'time' => AutomationScheduleSettings::masterInvariantsTime(),
            ]),
            'daily' => __('Daily at :time — store daily reconciliation snapshot', [
                'time' => AutomationScheduleSettings::dailyReconcileTime(),
            ]),
            'nightly' => __('Daily at :time — refresh exception queue (auto-resolve)', [
                'time' => AutomationScheduleSettings::nightlyReconcileTime(),
            ]),
            'monthly' => __('On day :day at :time — monthly snapshot + statements', [
                'day' => $monthDay,
                'time' => AutomationScheduleSettings::monthBoundaryTime(),
            ]),
        ];
    }

    protected function normalizeSideTab(): void
    {
        if (! array_key_exists($this->sideTab, $this->getReconciliationTabs())) {
            $this->sideTab = 'overview';
        }
    }

    protected bool $applyingSideTabFromMethod = false;

    public function setSideTab(string $tab): void
    {
        if (! array_key_exists($tab, $this->getReconciliationTabs())) {
            return;
        }

        if ($this->sideTab === $tab) {
            return;
        }

        $previousTab = $this->sideTab;

        $this->applyingSideTabFromMethod = true;

        try {
            $this->sideTab = $tab;
            $this->syncSideTabTableState($previousTab, $tab);
            $this->ensureSnapshotSelected();
            $this->ensureExceptionSelected();
        } finally {
            $this->applyingSideTabFromMethod = false;
        }
    }

    public function updatedSideTab(): void
    {
        $this->normalizeSideTab();

        if ($this->applyingSideTabFromMethod) {
            return;
        }

        if (in_array($this->sideTab, $this->tableSideTabs(), true)) {
            $this->tableSort = null;
            $this->reconfigureTableForSideTab();
            $this->resetTable();
        }
    }

    /**
     * @return list<string>
     */
    protected function tableSideTabs(): array
    {
        return ['exceptions', 'history'];
    }

    protected function syncSideTabTableState(string $from, string $to): void
    {
        $tableTabs = $this->tableSideTabs();

        if (! in_array($from, $tableTabs, true) && ! in_array($to, $tableTabs, true)) {
            return;
        }

        $this->tableSort = null;

        if (in_array($from, $tableTabs, true)) {
            $this->unmountTableAction(false);
        }

        $this->reconfigureTableForSideTab();
        $this->resetTable();
    }

    protected function reconfigureTableForSideTab(): void
    {
        $this->table = $this->table($this->makeTable());

        $this->cacheSchema('tableFiltersForm', $this->getTableFiltersForm(...));

        $this->initTableColumnManager();

        $this->tableColumns = [];
        $this->cachedDefaultTableColumnState = null;

        $this->tableFilters = [];
        $this->getTableFiltersForm()->fill([]);
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        return 'reconciliation_'.$this->sideTab;
    }

    public function table(Table $table): Table
    {
        $query = match ($this->sideTab) {
            'history' => ReconciliationException::query()
                ->where('status', ReconciliationException::STATUS_RESOLVED)
                ->orderByDesc('resolved_at'),
            'exceptions' => ReconciliationException::query()
                ->whereIn('status', [
                    ReconciliationException::STATUS_OPEN,
                    ReconciliationException::STATUS_ESCALATED,
                ])
                ->when(
                    filled($this->queueDomainFilter),
                    fn ($query) => $query->where('domain', $this->queueDomainFilter),
                )
                ->orderByDesc('raised_at'),
            default => ReconciliationException::query(),
        };

        return ReconciliationExceptionsTable::configure(
            $table->query($query),
            queueOnly: $this->sideTab === 'exceptions',
            advancedUi: true,
            workspacePanel: $this->sideTab === 'exceptions',
            selectedExceptionId: $this->selectedExceptionId,
        );
    }

    /**
     * @return array<string, int>
     */
    public function getOpenExceptionCountByDomain(): array
    {
        if (! Schema::hasTable('reconciliation_exceptions')) {
            return [];
        }

        try {
            return ReconciliationException::query()
                ->open()
                ->selectRaw('domain, COUNT(*) as aggregate')
                ->groupBy('domain')
                ->pluck('aggregate', 'domain')
                ->map(fn ($count): int => (int) $count)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function getPendingBankClearanceCount(): int
    {
        try {
            return app(BankClearingMatchService::class)->pendingOperationalClearanceCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function getBankClearingUrl(): string
    {
        return BankAccountsResource::listUrl(
            BankClearingTabRegistry::TAB_QUEUE,
            queueFilter: BankClearingTabRegistry::FILTER_OPERATIONS,
        );
    }

    public function getReconciliationSettingsUrl(): string
    {
        return Settings::getUrl(['settingsTab' => 'reconciliation::tab']);
    }

    public function getOpenExceptionCount(): int
    {
        if (! Schema::hasTable('reconciliation_exceptions')) {
            return 0;
        }

        try {
            return ReconciliationException::query()->open()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function getResolvedExceptionCount(): int
    {
        if (! Schema::hasTable('reconciliation_exceptions')) {
            return 0;
        }

        try {
            return ReconciliationException::query()
                ->where('status', ReconciliationException::STATUS_RESOLVED)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function getLastNightlyBatch(): ?FundAuditLog
    {
        if (! Schema::hasTable('fund_audit_logs')) {
            return null;
        }

        try {
            return FundAuditLog::query()
                ->where('event_type', 'NIGHTLY_RECON_COMPLETE')
                ->latest('occurred_at')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    public function getLastBatchAutoResolvedCount(): int
    {
        $batch = $this->getLastNightlyBatch();

        if ($batch === null) {
            return 0;
        }

        return (int) ($batch->payload['resolved'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function getHealthSummary(): array
    {
        $summary = app(ReconciliationHealthSummary::class);

        return $summary->summarize(
            $this->getLatestSnapshots()->first(),
            $this->getOpenExceptionCount(),
            $summary->openCriticalCount(),
            $summary->openWarningCount(),
            $this->getPendingBankClearanceCount(),
            $this->getLastNightlyBatch(),
        );
    }

    /**
     * @return list<array{label: string, action: string, url: ?string, tab: ?string}>
     */
    public function getNextSteps(): array
    {
        return app(ReconciliationHealthSummary::class)->nextSteps();
    }

    public function getNextBatchRunAt(): Carbon
    {
        return app(ReconciliationHealthSummary::class)->nextBatchRunAt();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Workspace Filament actions are unused — runs use Livewire wire:click buttons
     * so feedback is reliable outside ActionGroup / modal plumbing.
     *
     * @return array<int, mixed>
     */
    protected function workspacePanelActions(): array
    {
        return [];
    }

    public function queueRealtimeReconciliation(): void
    {
        $this->queueReconciliationRun(ReconciliationSnapshot::MODE_REALTIME);
    }

    public function queueExceptionQueueRecheck(): void
    {
        $this->queueReconciliationRun(RunReconciliationJob::MODE_EXCEPTION_QUEUE);
    }

    public function queueDailySnapshot(): void
    {
        $this->queueReconciliationRun(ReconciliationSnapshot::MODE_DAILY);
    }

    public function queueMonthlySnapshot(): void
    {
        $this->queueReconciliationRun(ReconciliationSnapshot::MODE_MONTHLY);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function queueReconciliationRun(string $mode, array $options = []): void
    {
        abort_unless(auth('tenant')->user()?->is_admin === true, 403);

        try {
            $token = (string) Str::uuid();

            $this->dispatchReconciliationJob(
                $mode,
                $options !== []
                ? $options
                : ReconciliationReportService::bankOptionsFromSettings(),
                $token,
            );

            $label = match ($mode) {
                ReconciliationSnapshot::MODE_REALTIME => __('Real-time check'),
                ReconciliationSnapshot::MODE_DAILY => __('Daily snapshot'),
                ReconciliationSnapshot::MODE_MONTHLY => __('Monthly snapshot'),
                RunReconciliationJob::MODE_EXCEPTION_QUEUE => __('Exception queue re-check'),
                default => __('Reconciliation'),
            };

            $this->reconciliationRunToken = $token;
            $this->reconciliationRunQueuedAt = time();
            $this->reconciliationRunFeedback = __(':label is running in the background. Watch the notification bell — large tenants can take about a minute.', [
                'label' => $label,
            ]);

            $this->reconciliationQueuedNotification()->send();
        } catch (\Throwable $exception) {
            report($exception);

            $this->clearReconciliationRunBanner();

            Notification::make()
                ->title(__('Reconciliation run failed'))
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function refreshReconciliationRunStatus(): void
    {
        if ($this->reconciliationRunToken === null || $this->reconciliationRunToken === '') {
            $this->skipRender();

            return;
        }

        $status = RunReconciliationJob::uiRunStatus(
            Cache::get(RunReconciliationJob::uiRunCacheKey($this->reconciliationRunToken)),
        );

        if (
            in_array($status, [
                RunReconciliationJob::UI_RUN_STATUS_COMPLETED,
                RunReconciliationJob::UI_RUN_STATUS_FAILED,
            ], true)
        ) {
            $cached = Cache::get(RunReconciliationJob::uiRunCacheKey($this->reconciliationRunToken));
            $toast = RunReconciliationJob::uiRunToast($cached);

            $this->clearReconciliationRunBanner();

            if ($toast !== null) {
                $notification = Notification::make()
                    ->title($toast['title'])
                    ->body($toast['body'])
                    ->persistent();

                match ($toast['color']) {
                    'success' => $notification->success(),
                    'warning' => $notification->warning(),
                    'danger' => $notification->danger(),
                    default => null,
                };

                $notification->send();
            }

            if (in_array($this->sideTab, $this->tableSideTabs(), true)) {
                $this->resetTable();
                $this->ensureExceptionSelected();
            }

            return;
        }

        // Stop hammering PHP-FPM with full-page Livewire payloads if the job status never lands.
        if (
            $this->reconciliationRunQueuedAt !== null
            && (time() - $this->reconciliationRunQueuedAt) >= 180
        ) {
            if (filled($this->reconciliationRunToken)) {
                Cache::forget(RunReconciliationJob::uiRunCacheKey($this->reconciliationRunToken));
            }

            $this->reconciliationRunToken = null;
            $this->reconciliationRunQueuedAt = null;
            $this->reconciliationRunFeedback = __('Still running in the background. Watch the notification bell.');

            return;
        }

        // Status unchanged — avoid re-serializing the whole reconciliation workspace (~200KB).
        $this->skipRender();
    }

    public function dismissReconciliationRunFeedback(): void
    {
        $this->clearReconciliationRunBanner();
    }

    protected function clearReconciliationRunBanner(): void
    {
        if (filled($this->reconciliationRunToken)) {
            Cache::forget(RunReconciliationJob::uiRunCacheKey($this->reconciliationRunToken));
        }

        $this->reconciliationRunToken = null;
        $this->reconciliationRunQueuedAt = null;
        $this->reconciliationRunFeedback = null;
    }

    public function selectSnapshot(?int $id): void
    {
        $this->selectedSnapshotId = $id;
    }

    public function selectException(int|string|null $id): void
    {
        $this->selectedExceptionId = $id === null || $id === '' ? null : (int) $id;
    }

    public function setQueueDomainFilter(?string $domain): void
    {
        $this->queueDomainFilter = $this->queueDomainFilter === $domain ? null : $domain;
        $this->ensureExceptionSelected();
        $this->resetTable();
    }

    public function runExceptionAction(string $actionName): void
    {
        if ($this->selectedExceptionId === null) {
            return;
        }

        $this->mountTableAction($actionName, (string) $this->selectedExceptionId);
    }

    protected function ensureExceptionSelected(): void
    {
        if ($this->sideTab !== 'exceptions') {
            return;
        }

        $query = ReconciliationException::query()
            ->whereIn('status', [
                ReconciliationException::STATUS_OPEN,
                ReconciliationException::STATUS_ESCALATED,
            ])
            ->when(
                filled($this->queueDomainFilter),
                fn ($builder) => $builder->where('domain', $this->queueDomainFilter),
            );

        if ($this->selectedExceptionId !== null) {
            $exists = (clone $query)->whereKey($this->selectedExceptionId)->exists();

            if ($exists) {
                return;
            }
        }

        $firstId = (clone $query)->orderByDesc('raised_at')->value('id');
        $this->selectedExceptionId = is_numeric($firstId) ? (int) $firstId : null;
    }

    public function getSelectedException(): ?ReconciliationException
    {
        if ($this->selectedExceptionId === null) {
            return null;
        }

        return ReconciliationException::query()
            ->with('assignee')
            ->find($this->selectedExceptionId);
    }

    /**
     * @return array{total: int, critical: int, high: int, escalated: int, unassigned: int}
     */
    public function getOpenExceptionQueueStats(): array
    {
        if (! Schema::hasTable('reconciliation_exceptions')) {
            return [
                'total' => 0,
                'critical' => 0,
                'high' => 0,
                'escalated' => 0,
                'unassigned' => 0,
            ];
        }

        try {
            $base = ReconciliationException::query()
                ->whereIn('status', [
                    ReconciliationException::STATUS_OPEN,
                    ReconciliationException::STATUS_ESCALATED,
                ]);

            return [
                'total' => (int) (clone $base)->count(),
                'critical' => (int) (clone $base)->where('severity', 'critical')->count(),
                'high' => (int) (clone $base)->where('severity', 'high')->count(),
                'escalated' => (int) (clone $base)->where('status', ReconciliationException::STATUS_ESCALATED)->count(),
                'unassigned' => (int) (clone $base)->whereNull('assigned_to')->count(),
            ];
        } catch (\Throwable) {
            return [
                'total' => 0,
                'critical' => 0,
                'high' => 0,
                'escalated' => 0,
                'unassigned' => 0,
            ];
        }
    }

    public function canExportDownloads(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public function canManageSnapshots(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public function deleteSnapshot(int $id): void
    {
        $this->authorizeSnapshotManagement();

        ReconciliationSnapshot::query()->whereKey($id)->delete();

        $this->snapshotBulkSelection = array_values(array_diff(
            $this->snapshotBulkSelection,
            [$id],
        ));

        if ($this->selectedSnapshotId === $id) {
            $this->selectedSnapshotId = null;
            $this->ensureSnapshotSelected();
        }

        Notification::make()
            ->title(__('Snapshot deleted'))
            ->success()
            ->send();
    }

    public function deleteSelectedSnapshots(): void
    {
        $this->authorizeSnapshotManagement();

        $ids = array_values(array_unique(array_map(intval(...), $this->snapshotBulkSelection)));

        if ($ids === []) {
            return;
        }

        $deleted = ReconciliationSnapshot::query()->whereIn('id', $ids)->delete();

        $this->snapshotBulkSelection = [];

        if ($this->selectedSnapshotId !== null && ! ReconciliationSnapshot::query()->whereKey($this->selectedSnapshotId)->exists()) {
            $this->selectedSnapshotId = null;
            $this->ensureSnapshotSelected();
        }

        Notification::make()
            ->title(__(':count snapshot(s) deleted', ['count' => $deleted]))
            ->success()
            ->send();
    }

    public function toggleAllSnapshotsForDeletion(): void
    {
        $this->authorizeSnapshotManagement();

        $allIds = $this->getLatestSnapshots()
            ->pluck('id')
            ->map(fn (mixed $snapshotId): int => (int) $snapshotId)
            ->all();

        if ($allIds === []) {
            $this->snapshotBulkSelection = [];

            return;
        }

        $selected = array_map(intval(...), $this->snapshotBulkSelection);
        sort($selected);
        $sortedAll = $allIds;
        sort($sortedAll);

        $this->snapshotBulkSelection = $selected === $sortedAll ? [] : $allIds;
    }

    public function downloadReport(?int $id = null): StreamedResponse
    {
        $this->authorizeExport();
        $id ??= $this->selectedSnapshotId;

        if ($id === null) {
            abort(404);
        }

        $snapshot = ReconciliationSnapshot::query()->findOrFail($id);
        $filename = 'reconciliation-snapshot-'.$snapshot->id.'-'.$snapshot->as_of->format('Y-m-d-His').'.json';

        return response()->streamDownload(
            function () use ($snapshot): void {
                echo json_encode(
                    $snapshot->report,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
                );
            },
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }

    public function downloadPdf(?int $id = null): StreamedResponse
    {
        $this->authorizeExport();
        $id ??= $this->selectedSnapshotId;

        if ($id === null) {
            abort(404);
        }

        $snapshot = ReconciliationSnapshot::query()->findOrFail($id);

        return app(ReconciliationPdfService::class)->download($snapshot);
    }

    public function getLatestSnapshots()
    {
        return ReconciliationSnapshot::query()
            ->latest('as_of')
            ->limit(40)
            ->get();
    }

    public function getSelectedSnapshot(): ?ReconciliationSnapshot
    {
        if ($this->selectedSnapshotId === null) {
            return null;
        }

        return ReconciliationSnapshot::query()
            ->with('createdBy')
            ->find($this->selectedSnapshotId);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function dispatchReconciliationJob(string $mode, array $options = [], ?string $uiRunToken = null): void
    {
        $userId = auth('tenant')->id();

        RunReconciliationJob::dispatch(
            $mode,
            $options,
            $userId !== null ? (int) $userId : null,
            $uiRunToken,
        );
    }

    protected function reconciliationQueuedNotification(): Notification
    {
        return Notification::make()
            ->title(__('Reconciliation queued'))
            ->body(__('Running in the background. Watch the notification bell — large tenants can take about a minute.'))
            ->success()
            ->persistent();
    }

    protected function authorizeExport(): void
    {
        if (! $this->canExportDownloads()) {
            abort(403);
        }
    }

    protected function authorizeSnapshotManagement(): void
    {
        if (! $this->canManageSnapshots()) {
            abort(403);
        }
    }
}
