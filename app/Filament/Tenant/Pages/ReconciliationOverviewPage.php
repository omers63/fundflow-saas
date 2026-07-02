<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Support\Action;
use App\Filament\Tenant\Concerns\EmbedsAsAuditWorkspacePanel;
use App\Filament\Tenant\Concerns\InteractsWithAdvancedUi;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\ReconciliationExceptions\Tables\ReconciliationExceptionsTable;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Filament\Tenant\Support\ReconciliationTabRegistry;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Services\BankClearingMatchService;
use App\Services\ReconciliationPdfService;
use App\Services\ReconciliationReportService;
use App\Services\ReconciliationService;
use App\Support\Reconciliation\ReconciliationHealthSummary;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ReconciliationOverviewPage extends Page implements HasTable
{
    use EmbedsAsAuditWorkspacePanel;
    use InteractsWithAdvancedUi;
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

    public ?int $selectedExceptionId = null;

    #[Url(as: 'queueDomain')]
    public ?string $queueDomainFilter = null;

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
        return __('See whether the fund books are in balance and work through anything that needs attention.');
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
        return ReconciliationTabRegistry::tabs($this->advancedUi);
    }

    public function mount(): void
    {
        $this->mountAdvancedUi();
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

    protected function onAdvancedUiToggled(): void
    {
        $this->normalizeSideTab();
        $this->refreshWorkspacePanelActions();
        $this->resetStuckActionState();
    }

    public function runCheckNow(): void
    {
        abort_unless(auth('tenant')->user()?->is_admin === true, 403);

        $this->executeRun(
            ReconciliationSnapshot::MODE_REALTIME,
            ReconciliationReportService::bankOptionsFromSettings(),
        );
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
            advancedUi: $this->advancedUi,
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
     * @return array<int, Action|ActionGroup>
     */
    protected function workspacePanelActions(): array
    {
        $canRun = fn (): bool => auth('tenant')->user()?->is_admin === true;

        if (! $canRun()) {
            return [];
        }

        $bankSchema = $this->reconciliationBankSchema();

        $runRealtime = Action::make('run_realtime')
            ->label(__('Real-time snapshot'))
            ->icon('heroicon-o-play')
            ->color('primary')
            ->button()
            ->longRunning()
            ->longRunningMessage(__('Running real-time reconciliation checks and saving a snapshot.'))
            ->schema($bankSchema)
            ->modalHeading(__('Run real-time reconciliation'))
            ->modalDescription(__('Recomputes all checks as of this moment and stores a snapshot tagged realtime.'))
            ->action(fn (array $data) => $this->executeRun(ReconciliationSnapshot::MODE_REALTIME, $this->optionsFromActionData($data)));

        if (! $this->advancedUi) {
            return [];
        }

        $moreRuns = [
            Action::make('run_nightly')
                ->label(__('Exception queue re-check'))
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->longRunning()
                ->longRunningMessage(__('Re-running all reconciliation checks and refreshing the exception queue. This can take a minute on large tenants.'))
                ->modalHeading(__('Re-run exception checks now'))
                ->modalDescription(__('Runs the full reconciliation scan immediately — including member cash/fund invariants — auto-resolves eligible issues, and refreshes the exception queue in real time.'))
                ->action(function (): void {
                    try {
                        $result = app(ReconciliationService::class)->runNightlyBatch();

                        Notification::make()
                            ->title($result['halted']
                                ? __('Reconciliation halted')
                                : __('Reconciliation complete'))
                            ->body(__('Raised: :raised | Resolved: :resolved', [
                                'raised' => $result['raised'],
                                'resolved' => $result['resolved'],
                            ]))
                            ->color($result['halted'] ? 'danger' : 'success')
                            ->send();
                    } catch (\Throwable $exception) {
                        $this->notifyReconciliationRunFailed($exception);

                        return;
                    } finally {
                        $this->finishWorkspaceReconciliationRun();
                    }
                }),
            Action::make('run_daily')
                ->label(__('Daily snapshot'))
                ->icon('heroicon-o-calendar-days')
                ->longRunning()
                ->longRunningMessage(__('Recording the daily snapshot and running ledger checks.'))
                ->schema($bankSchema)
                ->modalHeading(__('Record daily snapshot'))
                ->modalDescription(__('Uses yesterday’s calendar window (app timezone) for period metrics, plus full ledger checks as of now.'))
                ->action(fn (array $data) => $this->executeRun(ReconciliationSnapshot::MODE_DAILY, $this->optionsFromActionData($data))),
            Action::make('run_monthly')
                ->label(__('Monthly snapshot'))
                ->icon('heroicon-o-calendar')
                ->longRunning()
                ->longRunningMessage(__('Recording the monthly snapshot and running ledger checks.'))
                ->schema($bankSchema)
                ->modalHeading(__('Record monthly snapshot'))
                ->modalDescription(__('Uses the previous calendar month for period metrics, plus full ledger checks as of now.'))
                ->action(fn (array $data) => $this->executeRun(ReconciliationSnapshot::MODE_MONTHLY, $this->optionsFromActionData($data))),
        ];

        return [
            $runRealtime,
            ActionGroup::make($moreRuns)
                ->label(__('More reconciliation runs'))
                ->icon('heroicon-o-ellipsis-horizontal')
                ->color('gray')
                ->button()
                ->dropdownPlacement('bottom-end'),
        ];
    }

    /**
     * @return array<int, DatePicker|TextInput|Toggle>
     */
    protected function reconciliationBankSchema(): array
    {
        return [
            TextInput::make('declared_bank_balance')
                ->label(__('Statement / bank closing balance'))
                ->numeric()
                ->nullable()
                ->helperText(__('Optional. Compared to master cash book balance for this run.')),
            DatePicker::make('declared_bank_date')
                ->label(__('Statement as-of date'))
                ->native(false)
                ->nullable(),
            Toggle::make('bank_mismatch_treat_as_critical')
                ->label(__('Treat bank vs book variance as critical (not only warning)'))
                ->default(false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function optionsFromActionData(array $data): array
    {
        $options = [];

        if (filled($data['declared_bank_balance'] ?? null)) {
            $options['declared_bank_balance'] = (float) $data['declared_bank_balance'];
        }

        if (filled($data['declared_bank_date'] ?? null)) {
            $options['declared_bank_date'] = Carbon::parse($data['declared_bank_date'])->toDateString();
        }

        $options['bank_mismatch_treat_as_critical'] = (bool) ($data['bank_mismatch_treat_as_critical'] ?? false);

        return $options;
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
    protected function executeRun(string $mode, array $options = []): void
    {
        try {
            @set_time_limit(0);

            $tz = config('app.timezone');
            $now = Carbon::now($tz);
            $service = app(ReconciliationReportService::class);

            if ($mode === ReconciliationSnapshot::MODE_REALTIME) {
                $report = $service->buildReport($mode, $now, null, null, $options);
            } elseif ($mode === ReconciliationSnapshot::MODE_DAILY) {
                $periodStart = $now->copy()->subDay()->startOfDay();
                $periodEnd = $now->copy()->subDay()->endOfDay();
                $report = $service->buildReport($mode, $now, $periodStart, $periodEnd, $options);
            } else {
                $anchor = $now->copy()->subMonthNoOverflow();
                $periodStart = $anchor->copy()->startOfMonth();
                $periodEnd = $anchor->copy()->endOfMonth();
                $report = $service->buildReport($mode, $now, $periodStart, $periodEnd, $options);
            }

            $userId = auth('tenant')->id();
            $snapshot = $service->persistSnapshot($report, is_int($userId) ? $userId : null);
            $this->selectedSnapshotId = $snapshot->id;

            if ($this->advancedUi) {
                $this->sideTab = 'snapshots';
            }

            $pass = $report['verdict']['pass'] ?? false;
            $notification = Notification::make()
                ->title($pass ? __('Reconciliation passed') : __('Reconciliation found critical issues'))
                ->body(__('Snapshot #:id — critical: :critical, warnings: :warnings', [
                    'id' => $snapshot->id,
                    'critical' => ($report['verdict']['critical_issues'] ?? 0),
                    'warnings' => ($report['verdict']['warnings'] ?? 0),
                ]));

            $pass ? $notification->success()->send() : $notification->danger()->send();
        } catch (\Throwable $exception) {
            $this->notifyReconciliationRunFailed($exception);
        } finally {
            $this->finishWorkspaceReconciliationRun();
        }
    }

    protected function finishWorkspaceReconciliationRun(): void
    {
        $this->resetStuckActionState();

        if (in_array($this->sideTab, $this->tableSideTabs(), true)) {
            $this->reconfigureTableForSideTab();
            $this->resetTable();
            $this->ensureExceptionSelected();
        }

        $this->refreshWorkspacePanelActions();
    }

    protected function resetStuckActionState(): void
    {
        if (method_exists($this, 'unmountAction')) {
            $this->unmountAction(false);
        }

        if (property_exists($this, 'mountedActions')) {
            $this->mountedActions = [];
        }

        if (property_exists($this, 'cachedMountedActions')) {
            $this->cachedMountedActions = null;
        }

        if (in_array($this->sideTab, $this->tableSideTabs(), true)) {
            $this->unmountTableAction(false);
        }
    }

    protected function notifyReconciliationRunFailed(\Throwable $exception): void
    {
        report($exception);

        Notification::make()
            ->title(__('Reconciliation run failed'))
            ->body($exception->getMessage())
            ->danger()
            ->send();
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
