<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Services\ReconciliationPdfService;
use App\Services\ReconciliationReportService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ReconciliationOverviewPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Reconciliation';

    protected static ?string $slug = 'reconciliation';

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?int $navigationSort = TenantNavigation::SORT_RECONCILIATION;

    protected string $view = 'filament.tenant.pages.reconciliation';

    /** @var 'overview'|'snapshots'|'methodology' */
    #[Url]
    public string $sideTab = 'overview';

    public ?int $selectedSnapshotId = null;

    public static function canAccess(): bool
    {
        return auth('tenant')->check();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Schema::hasTable('reconciliation_snapshots');
    }

    public function getTitle(): string
    {
        return __('Financial reconciliation');
    }

    public function getSubheading(): ?string
    {
        return __('Ledger integrity, bank vs book (optional), contributions, loans, pipeline — snapshots, JSON, and PDF.');
    }

    public function mount(): void
    {
        if (!in_array($this->sideTab, ['overview', 'snapshots', 'methodology'], true)) {
            $this->sideTab = 'overview';
        }
    }

    protected function getHeaderActions(): array
    {
        $canRun = fn(): bool => auth('tenant')->user()?->is_admin === true;

        $bankSchema = [
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

        return [
            Action::make('run_realtime')
                ->label(__('Run now (real-time)'))
                ->icon('heroicon-o-play')
                ->color('primary')
                ->visible($canRun)
                ->schema($bankSchema)
                ->modalHeading(__('Run real-time reconciliation'))
                ->modalDescription(__('Recomputes all checks as of this moment and stores a snapshot tagged realtime.'))
                ->action(fn(array $data) => $this->executeRun(ReconciliationSnapshot::MODE_REALTIME, $this->optionsFromActionData($data))),

            Action::make('run_daily')
                ->label(__('Daily snapshot'))
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->visible($canRun)
                ->schema($bankSchema)
                ->modalHeading(__('Record daily snapshot'))
                ->modalDescription(__('Uses yesterday’s calendar window (app timezone) for period metrics, plus full ledger checks as of now.'))
                ->action(fn(array $data) => $this->executeRun(ReconciliationSnapshot::MODE_DAILY, $this->optionsFromActionData($data))),

            Action::make('run_monthly')
                ->label(__('Monthly snapshot'))
                ->icon('heroicon-o-calendar')
                ->color('gray')
                ->visible($canRun)
                ->schema($bankSchema)
                ->modalHeading(__('Record monthly snapshot'))
                ->modalDescription(__('Uses the previous calendar month for period metrics, plus full ledger checks as of now.'))
                ->action(fn(array $data) => $this->executeRun(ReconciliationSnapshot::MODE_MONTHLY, $this->optionsFromActionData($data))),
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
