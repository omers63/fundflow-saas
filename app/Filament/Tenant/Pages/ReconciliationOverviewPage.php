<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Concerns\EmbedsAsAuditWorkspacePanel;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\ReconciliationExceptions\Tables\ReconciliationExceptionsTable;
use App\Filament\Tenant\Support\ReconciliationTabRegistry;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Services\BankClearingMatchService;
use App\Services\ReconciliationPdfService;
use App\Services\ReconciliationReportService;
use App\Services\ReconciliationService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
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
        if (!Schema::hasTable('reconciliation_exceptions')) {
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
        return __('Work the exception queue, review snapshots, and keep bank clearing in sync.');
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
        if (!array_key_exists($this->sideTab, $this->getReconciliationTabs())) {
            $this->sideTab = 'overview';
        }
    }

    public function setSideTab(string $tab): void
    {
        if (!array_key_exists($tab, $this->getReconciliationTabs())) {
            return;
        }

        if ($this->sideTab === $tab) {
            return;
        }

        $this->sideTab = $tab;

        if (in_array($tab, ['exceptions', 'history'], true)) {
            $this->resetTable();
        }
    }

    public function updatedSideTab(?string $value): void
    {
        if (in_array($value, ['exceptions', 'history'], true)) {
            $this->resetTable();
        }
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
                ->orderByDesc('raised_at'),
            default => ReconciliationException::query(),
        };

        return ReconciliationExceptionsTable::configure(
            $table->query($query),
            queueOnly: $this->sideTab === 'exceptions',
        );
    }

    /**
     * @return array<string, int>
     */
    public function getOpenExceptionCountByDomain(): array
    {
        if (!Schema::hasTable('reconciliation_exceptions')) {
            return [];
        }

        try {
            return ReconciliationException::query()
                ->open()
                ->selectRaw('domain, COUNT(*) as aggregate')
                ->groupBy('domain')
                ->pluck('aggregate', 'domain')
                ->map(fn($count): int => (int) $count)
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
        return BankAccountsResource::listUrl('clearance');
    }

    public function getReconciliationSettingsUrl(): string
    {
        return Settings::getUrl(['settingsTab' => 'reconciliation::tab']);
    }

    public function getOpenExceptionCount(): int
    {
        if (!Schema::hasTable('reconciliation_exceptions')) {
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
        if (!Schema::hasTable('reconciliation_exceptions')) {
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
        if (!Schema::hasTable('fund_audit_logs')) {
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

    public function getNextBatchRunAt(): Carbon
    {
        $tz = config('app.timezone');
        $next = Carbon::now($tz)->setTime(6, 30);

        if ($next->isPast()) {
            $next->addDay();
        }

        return $next;
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
        $canRun = fn(): bool => auth('tenant')->user()?->is_admin === true;
        $bankSchema = $this->reconciliationBankSchema();

        return [
            Action::make('run_realtime')
                ->label(__('Run now'))
                ->icon('heroicon-o-play')
                ->color('primary')
                ->visible($canRun)
                ->longRunning()
                ->longRunningMessage(__('Running real-time reconciliation checks and saving a snapshot.'))
                ->schema($bankSchema)
                ->modalHeading(__('Run real-time reconciliation'))
                ->modalDescription(__('Recomputes all checks as of this moment and stores a snapshot tagged realtime.'))
                ->action(fn(array $data) => $this->executeRun(ReconciliationSnapshot::MODE_REALTIME, $this->optionsFromActionData($data))),
            ActionGroup::make([
                Action::make('run_nightly')
                    ->label(__('Nightly batch'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->longRunningMessage(__('Running the nightly reconciliation batch. This can take a minute on large tenants.'))
                    ->modalHeading(__('Run reconciliation batch'))
                    ->modalDescription(__('Runs the nightly reconciliation scan, auto-resolves eligible issues, and refreshes the exception queue.'))
                    ->action(function (): void {
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

                        $this->resetTable();
                        $this->dispatch('$refresh');
                    }),
                Action::make('run_daily')
                    ->label(__('Daily snapshot'))
                    ->icon('heroicon-o-calendar-days')
                    ->longRunning()
                    ->longRunningMessage(__('Recording the daily snapshot and running ledger checks.'))
                    ->schema($bankSchema)
                    ->modalHeading(__('Record daily snapshot'))
                    ->modalDescription(__('Uses yesterday’s calendar window (app timezone) for period metrics, plus full ledger checks as of now.'))
                    ->action(fn(array $data) => $this->executeRun(ReconciliationSnapshot::MODE_DAILY, $this->optionsFromActionData($data))),
                Action::make('run_monthly')
                    ->label(__('Monthly snapshot'))
                    ->icon('heroicon-o-calendar')
                    ->longRunning()
                    ->longRunningMessage(__('Recording the monthly snapshot and running ledger checks.'))
                    ->schema($bankSchema)
                    ->modalHeading(__('Record monthly snapshot'))
                    ->modalDescription(__('Uses the previous calendar month for period metrics, plus full ledger checks as of now.'))
                    ->action(fn(array $data) => $this->executeRun(ReconciliationSnapshot::MODE_MONTHLY, $this->optionsFromActionData($data))),
            ])
                ->label(__('More runs'))
                ->icon('heroicon-o-ellipsis-horizontal')
                ->color('gray')
                ->button()
                ->dropdownPlacement('bottom-end')
                ->visible($canRun),
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

    public function canExportDownloads(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public function downloadReport(?int $id = null): StreamedResponse
    {
        $this->authorizeExport();
        $id ??= $this->selectedSnapshotId;

        if ($id === null) {
            abort(404);
        }

        $snapshot = ReconciliationSnapshot::query()->findOrFail($id);
        $filename = 'reconciliation-snapshot-' . $snapshot->id . '-' . $snapshot->as_of->format('Y-m-d-His') . '.json';

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

        return ReconciliationSnapshot::query()->find($this->selectedSnapshotId);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function executeRun(string $mode, array $options = []): void
    {
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

        $pass = $report['verdict']['pass'] ?? false;
        $notification = Notification::make()
            ->title($pass ? __('Reconciliation passed') : __('Reconciliation found critical issues'))
            ->body(__('Snapshot #:id — critical: :critical, warnings: :warnings', [
                'id' => $snapshot->id,
                'critical' => ($report['verdict']['critical_issues'] ?? 0),
                'warnings' => ($report['verdict']['warnings'] ?? 0),
            ]));

        $pass ? $notification->success()->send() : $notification->danger()->send();

        $this->dispatch('$refresh');
    }

    protected function authorizeExport(): void
    {
        if (!$this->canExportDownloads()) {
            abort(403);
        }
    }
}
