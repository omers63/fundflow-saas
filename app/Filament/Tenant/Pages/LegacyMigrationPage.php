<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Concerns\EmbedsAsAuditWorkspacePanel;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Jobs\Tenant\RunLegacyMigrationPaymentsJob;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use App\Services\LegacyMigration\LegacyMigrationOrchestrator;
use App\Services\LegacyMigration\LegacyMigrationPreviewService;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use App\Support\BusinessDay;
use App\Support\FilamentStoredUploadPath;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class LegacyMigrationPage extends Page implements HasForms
{
    use EmbedsAsAuditWorkspacePanel;
    use InteractsWithForms;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?string $navigationLabel = 'Legacy migration';

    protected static ?string $slug = 'legacy-migration';

    protected static ?int $navigationSort = TenantNavigation::SORT_MIGRATIONS;

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    protected string $view = 'filament.tenant.pages.legacy-migration';

    protected string $embeddedView = 'filament.tenant.pages.embedded.legacy-migration';

    public int $currentStep = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $lastPreview = null;

    /** @var array<string, mixed>|null */
    public ?array $lastRun = null;

    /** @var array<string, mixed>|null */
    public ?array $classificationStats = null;

    /** @var list<string> */
    public array $classificationErrors = [];

    public bool $classifiedPaymentsReady = false;

    public bool $migrationRunning = false;

    public ?string $migrationLastError = null;

    private ?string $lastKnownMigrationStatus = null;

    private const CLASSIFIED_PAYMENTS_PATH = LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH;

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(bool $embedded = false): void
    {
        $this->mountEmbedded($embedded);

        $this->form->fill([
            'strategy' => 'snapshot',
            'cutoff_date' => BusinessDay::now()->subMonth()->endOfMonth()->toDateString(),
            'default_password' => '',
        ]);

        $this->classifiedPaymentsReady = Storage::disk('local')->exists(self::CLASSIFIED_PAYMENTS_PATH);
        $this->refreshMigrationStateFromSettings();
    }

    public function pollMigrationStatus(): void
    {
        $previousStatus = $this->lastKnownMigrationStatus;
        $this->refreshMigrationStateFromSettings();

        $currentStatus = (string) Setting::get('legacy_migration', 'run_status', 'idle');

        if ($previousStatus === 'running' && $currentStatus === 'completed' && $this->lastRun !== null) {
            $members = $this->lastRun['members'] ?? [];

            Notification::make()
                ->title(__('Migration complete'))
                ->body(__('Created: :created · Skipped: :skipped · Failed: :failed', [
                    'created' => $members['created'] ?? 0,
                    'skipped' => $members['skipped'] ?? 0,
                    'failed' => $members['failed'] ?? 0,
                ]))
                ->success()
                ->send();
        }

        if ($previousStatus === 'running' && $currentStatus === 'failed' && filled($this->migrationLastError)) {
            Notification::make()
                ->title(__('Migration failed'))
                ->body($this->migrationLastError)
                ->danger()
                ->persistent()
                ->send();
        }

        $this->lastKnownMigrationStatus = $currentStatus;
    }

    public function getTitle(): string
    {
        return __('Legacy migration');
    }

    public function getSubheading(): ?string
    {
        return __('Import members, loans, and optional payment history from your previous system using CSV templates.');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<Action>
     */
    protected function workspacePanelActions(): array
    {
        return [
            Action::make('previewMigration')
                ->label(__('Preview'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->action(fn(): mixed => $this->previewMigration()),
            Action::make('classifyPayments')
                ->label(__('Classify payments'))
                ->icon('heroicon-o-tag')
                ->color('gray')
                ->action(fn(): mixed => $this->classifyPayments()),
            Action::make('dryRun')
                ->label(__('Dry run'))
                ->icon('heroicon-o-beaker')
                ->color('warning')
                ->action(fn(): mixed => $this->runMigration(true)),
            Action::make('runMigration')
                ->label(__('Run migration'))
                ->icon('heroicon-o-play')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading(__('Run migration now?'))
                ->modalDescription(__('This writes members, loans, and optional payments to the database.'))
                ->action(fn(): mixed => $this->runMigration(false)),
        ];
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fi-page-legacy-migration'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Radio::make('strategy')
                    ->label(__('Migration strategy'))
                    ->options([
                        'snapshot' => __('Snapshot (recommended) — opening balances at cut-off; skip ambiguous payments'),
                        'historical' => __('Historical — also import classified payment rows after members and loans'),
                    ])
                    ->default('snapshot')
                    ->live(),
                DatePicker::make('cutoff_date')
                    ->label(__('Migration cut-off date'))
                    ->required()
                    ->maxDate(BusinessDay::now())
                    ->native(false)
                    ->helperText(__('Balances and arrears before this date are treated as legacy. Late fees and delinquency history are not imported.')),
                TextInput::make('default_password')
                    ->label(__('Default member password'))
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->helperText(__('Required to run the migration. Used for imported members when the CSV password column is empty.')),
                FileUpload::make('members_csv')
                    ->label(__('Members CSV'))
                    ->disk('local')
                    ->directory('legacy-migration')
                    ->maxFiles(1)
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->helperText(__('Required. Wait until each file finishes uploading before preview or dry run.')),
                FileUpload::make('loans_csv')
                    ->label(__('Loans CSV'))
                    ->disk('local')
                    ->directory('legacy-migration')
                    ->maxFiles(1)
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->helperText(__('Optional. Import active loans with paid_installments_count and total_amount_repaid.')),
                FileUpload::make('payments_csv')
                    ->label(__('Payments CSV'))
                    ->disk('local')
                    ->directory('legacy-migration')
                    ->maxFiles(1)
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->visible(fn(): bool => ($this->data['strategy'] ?? 'snapshot') === 'historical')
                    ->helperText(__('Optional for historical strategy. Classify rows before import — members (and loans) CSV on this page are used to match payment rows.')),
            ]);
    }

    public function goToStep(int $step): void
    {
        $this->currentStep = max(1, min(5, $step));
    }

    public function nextStep(): void
    {
        $this->goToStep($this->currentStep + 1);
    }

    public function previousStep(): void
    {
        $this->goToStep($this->currentStep - 1);
    }

    public function previewMigration(): void
    {
        try {
            $state = $this->form->getState();
        } catch (ValidationException $exception) {
            $this->notifyFormValidationFailed($exception);

            return;
        }

        $paths = $this->resolveUploadedPathsFromState($state);

        if ($paths['members'] === null) {
            Notification::make()
                ->title(__('Members CSV required'))
                ->body(__('Upload a members CSV and wait until it finishes uploading before previewing.'))
                ->warning()
                ->send();

            return;
        }

        $previewService = app(LegacyMigrationPreviewService::class);

        $this->lastPreview = [
            'members' => $previewService->previewMembers($paths['members']),
            'loans' => $previewService->previewLoans($paths['loans']),
            'payments' => ($state['strategy'] ?? 'snapshot') === 'historical'
                ? $previewService->previewPayments($paths['payments'])
                : null,
        ];

        $this->currentStep = 5;

        Notification::make()
            ->title(__('Preview ready'))
            ->success()
            ->send();
    }

    public function classifyPayments(): void
    {
        try {
            $state = $this->form->getState();
        } catch (ValidationException $exception) {
            $this->notifyFormValidationFailed($exception);

            return;
        }

        if (($state['strategy'] ?? 'snapshot') !== 'historical') {
            Notification::make()
                ->title(__('Historical strategy required'))
                ->body(__('Switch migration strategy to Historical to classify or import payments.'))
                ->warning()
                ->send();

            return;
        }

        $paths = $this->resolveUploadedPathsFromState($state);

        if ($paths['payments'] === null) {
            Notification::make()
                ->title(__('Payments CSV required'))
                ->body(__('Upload a payments CSV and wait until it finishes uploading before classifying.'))
                ->warning()
                ->send();

            return;
        }

        if ($paths['loans'] === null && !Loan::query()->whereNotNull('disbursed_at')->exists()) {
            Notification::make()
                ->title(__('Loans CSV required'))
                ->body(__('Upload a loans CSV or import loans first. Repayment windows cannot be detected without loan disbursement dates.'))
                ->warning()
                ->send();

            return;
        }

        try {
            $cutoff = filled($state['cutoff_date'] ?? null)
                ? Carbon::parse((string) $state['cutoff_date'])
                : null;

            $result = app(LegacyPaymentClassifierService::class)->classifyFile(
                $paths['payments'],
                $cutoff,
                $paths['members'],
                $paths['loans'],
            );
            $this->classificationStats = $result['stats'];
            $this->classificationErrors = array_slice($result['errors'] ?? [], 0, 10);
            $this->classifiedPaymentsReady = $result['rows'] !== [];

            if ($this->classifiedPaymentsReady) {
                app(LegacyPaymentClassifierService::class)->writeClassifiedCsv(
                    Storage::disk('local')->path(self::CLASSIFIED_PAYMENTS_PATH),
                    $result['rows'],
                );
            }

            $body = __('Contributions: :c · Loan repayments: :l · Unclassified: :u · Ignored: :i · Failed: :f', [
                'c' => $result['stats']['contribution'],
                'l' => $result['stats']['loan_repayment'],
                'u' => $result['stats']['unclassified'],
                'i' => $result['stats']['ignore'],
                'f' => $result['stats']['failed'] ?? 0,
            ]);

            if (($result['errors'] ?? []) !== []) {
                $preview = implode("\n", array_slice($result['errors'], 0, 5));
                if (count($result['errors']) > 5) {
                    $preview .= "\n… " . __('and :count more row error(s)', ['count' => count($result['errors']) - 5]);
                }

                $body .= "\n\n" . $preview;
            }

            Notification::make()
                ->title(__('Payments classified'))
                ->body($body . ($this->classifiedPaymentsReady
                    ? "\n\n" . __('Download the full classified CSV from the results panel below.')
                    : ''))
                ->color(($result['stats']['failed'] ?? 0) > 0 ? 'warning' : 'success')
                ->persistent(($result['stats']['failed'] ?? 0) > 0)
                ->send();

            $this->currentStep = 4;
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Classification failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function downloadClassifiedPayments(): StreamedResponse
    {
        abort_unless($this->classifiedPaymentsReady && Storage::disk('local')->exists(self::CLASSIFIED_PAYMENTS_PATH), 404);

        return Storage::disk('local')->download(
            self::CLASSIFIED_PAYMENTS_PATH,
            'legacy-payments-classified.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    public function runMigration(bool $dryRun = false): void
    {
        try {
            $state = $this->form->getState();
        } catch (ValidationException $exception) {
            $this->notifyFormValidationFailed($exception);

            return;
        }

        $paths = $this->resolveUploadedPathsFromState($state);
        $password = (string) ($state['default_password'] ?? '');

        if ($paths['members'] === null) {
            Notification::make()
                ->title(__('Members CSV required'))
                ->body(__('Upload a members CSV and wait until it finishes uploading before running the migration.'))
                ->danger()
                ->send();

            return;
        }

        if (strlen($password) < 8) {
            Notification::make()
                ->title(__('Default password required'))
                ->body(__('Enter a default password of at least 8 characters.'))
                ->warning()
                ->send();

            return;
        }

        if (!$dryRun && Setting::get('legacy_migration', 'run_status') === 'running') {
            Notification::make()
                ->title(__('Migration already running'))
                ->body(__('A migration is already in progress. This page will update when it finishes.'))
                ->warning()
                ->send();

            return;
        }

        $options = [
            'cutoff_date' => $state['cutoff_date'] ?? null,
            'default_password' => $password,
            'members_path' => $paths['members'],
            'loans_path' => $paths['loans'],
            'payments_path' => $paths['payments'],
            'classified_payments_path' => Storage::disk('local')->exists(self::CLASSIFIED_PAYMENTS_PATH)
                ? Storage::disk('local')->path(self::CLASSIFIED_PAYMENTS_PATH)
                : null,
            'strategy' => $state['strategy'] ?? 'snapshot',
        ];

        if ($dryRun) {
            try {
                $this->lastRun = app(LegacyMigrationOrchestrator::class)->run($options, true);

                $members = $this->lastRun['members'];

                Notification::make()
                    ->title(__('Dry run complete'))
                    ->body(__('Would import :count member row(s). Review the summary below.', ['count' => $members['created']]))
                    ->success()
                    ->send();

                $this->currentStep = 5;
            } catch (\Throwable $e) {
                report($e);

                Notification::make()
                    ->title(__('Dry run failed'))
                    ->body($e->getMessage())
                    ->danger()
                    ->persistent()
                    ->send();
            }

            return;
        }

        try {
            @set_time_limit(0);

            Setting::set('legacy_migration', 'run_status', 'running');
            Setting::set('legacy_migration', 'last_error', '');
            $this->migrationRunning = true;
            $this->lastKnownMigrationStatus = 'running';

            $orchestrator = app(LegacyMigrationOrchestrator::class);
            $memberLoanResult = LegacyMigrationOrchestrator::summarizeForDisplay(
                $orchestrator->importMembersAndLoans($options),
            );

            $this->lastRun = $memberLoanResult;
            Setting::set('legacy_migration', 'last_run', json_encode($memberLoanResult, JSON_UNESCAPED_UNICODE));

            $members = $memberLoanResult['members'];

            if ($orchestrator->shouldQueuePaymentImport($options)) {
                RunLegacyMigrationPaymentsJob::dispatch(
                    $options,
                    $paths['relatives'],
                    auth('tenant')->id(),
                );

                Notification::make()
                    ->title(__('Members and loans imported'))
                    ->body(__('Created: :created · Skipped: :skipped · Failed: :failed. Payment import is running in the background.', [
                        'created' => $members['created'] ?? 0,
                        'skipped' => $members['skipped'] ?? 0,
                        'failed' => $members['failed'] ?? 0,
                    ]))
                    ->success()
                    ->send();
            } else {
                Setting::set('legacy_migration', 'run_status', 'completed');
                $this->migrationRunning = false;
                $this->cleanupUploadedPaths($paths);

                Notification::make()
                    ->title(__('Migration complete'))
                    ->body(__('Created: :created · Skipped: :skipped · Failed: :failed', [
                        'created' => $members['created'] ?? 0,
                        'skipped' => $members['skipped'] ?? 0,
                        'failed' => $members['failed'] ?? 0,
                    ]))
                    ->success()
                    ->send();
            }

            $this->currentStep = 5;
        } catch (\Throwable $e) {
            report($e);

            Setting::set('legacy_migration', 'run_status', 'failed');
            Setting::set('legacy_migration', 'last_error', $e->getMessage());
            $this->migrationRunning = false;
            $this->migrationLastError = $e->getMessage();

            Notification::make()
                ->title(__('Migration failed'))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    private function refreshMigrationStateFromSettings(): void
    {
        $status = (string) Setting::get('legacy_migration', 'run_status', 'idle');
        $this->migrationRunning = $status === 'running';
        $this->migrationLastError = (string) Setting::get('legacy_migration', 'last_error', '');
        $this->lastKnownMigrationStatus ??= $status;

        $lastRunJson = Setting::get('legacy_migration', 'last_run');

        if (is_string($lastRunJson) && $lastRunJson !== '') {
            $decoded = json_decode($lastRunJson, true);

            if (is_array($decoded)) {
                $this->lastRun = $decoded;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{members: ?string, loans: ?string, payments: ?string, relatives: list<string>}
     */
    private function resolveUploadedPathsFromState(array $state): array
    {
        $relatives = [];
        $members = $this->resolveCsvPath($state['members_csv'] ?? null, $relatives);
        $loans = $this->resolveCsvPath($state['loans_csv'] ?? null, $relatives);
        $payments = $this->resolveCsvPath($state['payments_csv'] ?? null, $relatives);

        return [
            'members' => $members,
            'loans' => $loans,
            'payments' => $payments,
            'relatives' => $relatives,
        ];
    }

    /**
     * @param  array{members: ?string, loans: ?string, payments: ?string, relatives: list<string>}  $paths
     */
    private function cleanupUploadedPaths(array $paths): void
    {
        foreach ($paths['relatives'] as $relative) {
            try {
                Storage::disk('local')->delete($relative);
            } catch (\Throwable) {
            }
        }
    }

    private function notifyFormValidationFailed(ValidationException $exception): void
    {
        Notification::make()
            ->title(__('Check the form'))
            ->body(collect($exception->errors())->flatten()->first() ?? __('Fix the highlighted fields and try again.'))
            ->danger()
            ->send();
    }

    /**
     * @param  list<string>  $relatives
     */
    private function resolveCsvPath(mixed $raw, array &$relatives): ?string
    {
        $resolved = FilamentStoredUploadPath::tryResolveReadableCsvToAbsolutePath($raw);

        if ($resolved === null) {
            return null;
        }

        if ($resolved['relativePathForDeletion'] !== null) {
            $relatives[] = $resolved['relativePathForDeletion'];
        }

        return $resolved['absolutePath'];
    }
}
