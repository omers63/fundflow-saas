<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Pages\Page;
use App\Filament\Support\Action;
use App\Filament\Tenant\Concerns\EmbedsAsAuditWorkspacePanel;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Jobs\Tenant\ClassifyLegacyPaymentsJob;
use App\Jobs\Tenant\ImportLegacyLoansJob;
use App\Jobs\Tenant\ImportLegacyMembersJob;
use App\Jobs\Tenant\RunLegacyMigrationPaymentsJob;
use App\Models\Tenant\Setting;
use App\Services\LegacyMigration\IncompleteLegacyImportException;
use App\Services\LegacyMigration\LegacyImportFailureRecorder;
use App\Services\LegacyMigration\LegacyMigrationOrchestrator;
use App\Services\LegacyMigration\LegacyMigrationWorkingCopy;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use App\Support\BusinessDay;
use App\Support\LegacyMigrationDateFormatSettings;
use App\Support\LegacyMigrationFundingStrategySettings;
use App\Support\LegacyMigrationGraceCycleSettings;
use App\Support\LegacyMigrationSettlementThresholdSettings;
use App\Support\LegacyMigrationUploadDiagnostics;
use App\Support\Utf8CsvStream;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class LegacyMigrationPage extends Page implements HasForms
{
    use EmbedsAsAuditWorkspacePanel;
    use InteractsWithForms;
    use TranslatesPageNavigationLabel;
    use WithFileUploads;

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
    public ?array $lastRun = null;

    /** @var array<string, mixed>|null */
    public ?array $classificationStats = null;

    /** @var list<string> */
    public array $classificationErrors = [];

    public bool $classifiedPaymentsReady = false;

    public bool $classificationRunning = false;

    public bool $membersImportRunning = false;

    public bool $loansImportRunning = false;

    public bool $migrationRunning = false;

    public ?string $migrationLastError = null;

    /**
     * Foldable results panels (members / loans / classify / apply).
     *
     * @var array<string, mixed>|null
     */
    public ?array $membersResultsPanel = null;

    /** @var array<string, mixed>|null */
    public ?array $loansResultsPanel = null;

    /** @var array<string, mixed>|null */
    public ?array $classificationResultsPanel = null;

    /** @var array<string, mixed>|null */
    public ?array $migrationResultsPanel = null;

    public ?string $lastKnownMigrationStatus = null;

    public ?string $lastKnownClassificationStatus = null;

    public ?string $lastKnownMembersImportStatus = null;

    public ?string $lastKnownLoansImportStatus = null;

    /** @var array<string, mixed>|null */
    public ?array $uploadDiagnostics = null;

    public $pendingMembersCsv = null;

    public $pendingLoansCsv = null;

    public $pendingPaymentsCsv = null;

    private const CLASSIFIED_PAYMENTS_PATH = LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH;

    public const WIZARD_STEP_COUNT = 5;

    private const SETTING_MEMBERS_IMPORTED = 'members_imported';

    private const SETTING_LOANS_IMPORTED = 'loans_imported';

    private const SETTING_MEMBERS_IMPORT_STATUS = 'members_import_status';

    private const SETTING_LOANS_IMPORT_STATUS = 'loans_import_status';

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
        $this->redirectToAuditWorkspaceUnlessEmbedded('migration');

        $this->form->fill([
            'strategy' => 'historical',
            'cutoff_date' => BusinessDay::now()->subMonth()->endOfMonth()->toDateString(),
            'default_password' => '',
            'slash_date_format' => LegacyMigrationDateFormatSettings::slashDateFormat(),
            'grace_cycles' => LegacyMigrationGraceCycleSettings::graceCycles(),
            'loan_funding_strategy' => LegacyMigrationFundingStrategySettings::fundingStrategy(),
            'skip_settlement_threshold' => LegacyMigrationSettlementThresholdSettings::skipSettlementThreshold(),
        ]);

        $this->classifiedPaymentsReady = Storage::disk('local')->exists(self::CLASSIFIED_PAYMENTS_PATH);
        $this->refreshMigrationStateFromSettings();
        $this->refreshClassificationStateFromSettings();
        $this->refreshUploadDiagnostics();
        $this->lastKnownClassificationStatus = (string) Setting::get('legacy_migration', 'classify_status', 'idle');
        $this->lastKnownMembersImportStatus = (string) Setting::get('legacy_migration', self::SETTING_MEMBERS_IMPORT_STATUS, 'idle');
        $this->lastKnownLoansImportStatus = (string) Setting::get('legacy_migration', self::SETTING_LOANS_IMPORT_STATUS, 'idle');
        $this->refreshMembersImportStateFromSettings();
        $this->refreshLoansImportStateFromSettings();
        $this->lastKnownMigrationStatus = (string) Setting::get('legacy_migration', 'run_status', 'idle');
    }

    public function pollWizardStepStatus(): void
    {
        $this->pollMembersImportStatus();
        $this->pollLoansImportStatus();
        $this->pollClassificationStatus();
    }

    public function pollMembersImportStatus(): void
    {
        if ($this->currentStep !== 1) {
            return;
        }

        $previousStatus = $this->lastKnownMembersImportStatus ?? 'idle';
        $this->refreshMembersImportStateFromSettings();

        $currentStatus = (string) Setting::get('legacy_migration', self::SETTING_MEMBERS_IMPORT_STATUS, 'idle');

        if ($previousStatus === 'running' && $currentStatus === 'completed') {
            $members = $this->decodedImportResult('members_import_result')['members'] ?? [];
            $failed = (int) ($members['failed'] ?? 0);
            $hasErrors = $failed > 0 || count($members['errors'] ?? []) > 0;

            Notification::make()
                ->title($hasErrors ? __('Members imported with warnings') : __('Members imported'))
                ->body(__('Members created: :created · Skipped: :skipped · Failed: :failed', [
                    'created' => $members['created'] ?? 0,
                    'skipped' => $members['skipped'] ?? 0,
                    'failed' => $failed,
                ]).(
                    $hasErrors
                    ? ' '.__('Open the import results panel for row-level details.')
                    : ''
                ))
                ->color($hasErrors ? 'warning' : 'success')
                ->send();
        }

        if ($previousStatus === 'running' && $currentStatus === 'failed') {
            $message = (string) ($this->membersResultsPanel['message']
                ?? Setting::get('legacy_migration', 'members_import_error', __('Import failed')));

            Notification::make()
                ->title(__('Import failed'))
                ->body(filled($message) ? $message : __('Import failed'))
                ->danger()
                ->persistent()
                ->send();
        }

        $this->lastKnownMembersImportStatus = $currentStatus;
    }

    public function pollLoansImportStatus(): void
    {
        if ($this->currentStep !== 2) {
            return;
        }

        $previousStatus = $this->lastKnownLoansImportStatus ?? 'idle';
        $this->refreshLoansImportStateFromSettings();

        $currentStatus = (string) Setting::get('legacy_migration', self::SETTING_LOANS_IMPORT_STATUS, 'idle');

        if ($previousStatus === 'running' && $currentStatus === 'completed') {
            $loans = $this->decodedImportResult('loans_import_result')['loans'] ?? [];
            $failed = (int) ($loans['failed'] ?? 0);
            $hasErrors = $failed > 0 || count($loans['errors'] ?? []) > 0;

            Notification::make()
                ->title($hasErrors ? __('Loans imported with warnings') : __('Loans imported'))
                ->body(__('Loans created: :loans · Failed: :failed', [
                    'loans' => $loans['created'] ?? 0,
                    'failed' => $failed,
                ]).(
                    $hasErrors
                    ? ' '.__('Open the import results panel for row-level details.')
                    : ''
                ))
                ->color($hasErrors ? 'warning' : 'success')
                ->send();
        }

        if ($previousStatus === 'running' && $currentStatus === 'failed') {
            $message = (string) ($this->loansResultsPanel['message'] ?? Setting::get('legacy_migration', 'loans_import_error', __('Import failed')));

            Notification::make()
                ->title(__('Import failed'))
                ->body(filled($message) ? $message : __('Import failed'))
                ->danger()
                ->persistent()
                ->send();
        }

        $this->lastKnownLoansImportStatus = $currentStatus;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedImportResult(string $settingKey): array
    {
        $json = Setting::get('legacy_migration', $settingKey);

        if (! is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function pollReviewStepStatus(): void
    {
        $this->pollWizardStepStatus();
    }

    public function pollClassificationStatus(): void
    {
        if ($this->currentStep !== 3 && $this->currentStep !== 4) {
            return;
        }

        $previousStatus = $this->lastKnownClassificationStatus ?? 'idle';
        $this->refreshClassificationStateFromSettings();

        $currentStatus = (string) Setting::get('legacy_migration', 'classify_status', 'idle');

        if ($previousStatus === 'running' && $currentStatus === 'completed') {
            Notification::make()
                ->title($this->classifiedPaymentsReady ? __('Payments classified') : __('Classification finished with errors'))
                ->body($this->classificationSummaryNotificationBody())
                ->color($this->classifiedPaymentsReady ? 'success' : 'warning')
                ->send();

            if ($this->currentStep === 3) {
                $this->currentStep = 4;
            }
        }

        if ($previousStatus === 'running' && $currentStatus === 'failed') {
            Notification::make()
                ->title(__('Classification failed'))
                ->body((string) Setting::get('legacy_migration', 'classify_error', __('Classification failed')))
                ->danger()
                ->persistent()
                ->send();
        }

        $this->lastKnownClassificationStatus = $currentStatus;
    }

    public function pollMigrationStatus(): void
    {
        $previousStatus = $this->lastKnownMigrationStatus ?? 'idle';
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
        return __('Import members, loans, and payment history from CSV using a five-step pipeline.');
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
            Action::make('importMembers')
                ->label(__('Import members'))
                ->icon('heroicon-o-users')
                ->color('gray')
                ->requiresConfirmation()
                ->longRunning()
                ->longRunningMessage(__('Importing members. This can take a few minutes on large files.'))
                ->modalHeading(__('Import members now?'))
                ->modalDescription(__('Creates member accounts and opening balances from the members CSV.'))
                ->disabled(fn (): bool => $this->membersImportRunning)
                ->action(fn (): mixed => $this->importMembers()),
            Action::make('importLoans')
                ->label(__('Import loans'))
                ->icon('heroicon-o-banknotes')
                ->color('gray')
                ->requiresConfirmation()
                ->longRunning()
                ->longRunningMessage(__('Importing loans and building repayment windows. This can take a few minutes.'))
                ->modalHeading(__('Import loans now?'))
                ->modalDescription(__('Creates loan records and repayment schedules from the loans CSV. The payments CSV is used to calculate each member fund top-up on the disbursement date.'))
                ->disabled(fn (): bool => $this->loansImportRunning || ! $this->membersImported() || ! $this->hasWorkingLoansCsv() || ! $this->hasWorkingPaymentsCsv())
                ->action(fn (): mixed => $this->importLoans()),
            Action::make('classifyPayments')
                ->label(__('Classify payments'))
                ->icon('heroicon-o-tag')
                ->color('gray')
                ->disabled(fn (): bool => $this->classificationRunning || ! $this->loansImported())
                ->action(fn (): mixed => $this->classifyPayments()),
            Action::make('dryRun')
                ->label(__('Dry run'))
                ->icon('heroicon-o-beaker')
                ->color('warning')
                ->disabled(fn (): bool => ! $this->classifiedPaymentsReady)
                ->action(fn (): mixed => $this->runMigration(true)),
            Action::make('runMigration')
                ->label(__('Apply migration'))
                ->icon('heroicon-o-play')
                ->color('primary')
                ->requiresConfirmation()
                ->longRunning()
                ->longRunningMessage(__('Replaying classified payment rows. This can take several minutes.'))
                ->modalHeading(__('Apply migration now?'))
                ->modalDescription(__('Posts contribution and loan repayment rows from the classified CSV. Members and loans must already be imported.'))
                ->disabled(fn (): bool => ! $this->classifiedPaymentsReady || $this->migrationRunning)
                ->action(fn (): mixed => $this->runMigration(false)),
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
                Section::make(__('Cut-off & defaults'))
                    ->description(__('Balances and arrears before the cut-off are treated as legacy.'))
                    ->schema([
                        DatePicker::make('cutoff_date')
                            ->label(__('Migration cut-off date'))
                            ->required()
                            ->maxDate(BusinessDay::now())
                            ->native(false)
                            ->helperText(__('Usually month-end before go-live. Late fees and delinquency history are not imported.')),
                        Select::make('slash_date_format')
                            ->label(__('Ambiguous slash dates'))
                            ->options(LegacyMigrationDateFormatSettings::slashDateFormatOptions())
                            ->default(LegacyMigrationDateFormatSettings::defaultSlashDateFormat())
                            ->required()
                            ->helperText(__('How to read dates like 11/3/2025 when both parts are 12 or less. ISO dates (2025-11-03) always parse correctly.')),
                        TextInput::make('default_password')
                            ->label(__('Default member password'))
                            ->password()
                            ->revealable()
                            ->helperText(__('Required when you import members. Used when the members CSV password column is empty.')),
                    ])
                    ->visible(fn (): bool => $this->currentStep === 1),
                Section::make(__('Loan import settings'))
                    ->description(__('Applied when loan rows are imported. Repayment windows open on disbursement.'))
                    ->schema([
                        Select::make('grace_cycles')
                            ->label(__('Grace cycles before first repayment'))
                            ->options(LegacyMigrationGraceCycleSettings::graceCycleOptions())
                            ->default(LegacyMigrationGraceCycleSettings::defaultGraceCycles())
                            ->required()
                            ->native(false)
                            ->helperText(__('Grace cycles set the first EMI cycle on imported loans.')),
                        Select::make('loan_funding_strategy')
                            ->label(__('Loan funding strategy'))
                            ->options(LegacyMigrationFundingStrategySettings::fundingStrategyOptions())
                            ->default(LegacyMigrationFundingStrategySettings::defaultFundingStrategy())
                            ->required()
                            ->native(false)
                            ->helperText(__('Used when the loans CSV omits member_portion and master_portion.')),
                        Toggle::make('skip_settlement_threshold')
                            ->label(__('Skip settlement threshold'))
                            ->default(LegacyMigrationSettlementThresholdSettings::defaultSkipSettlementThreshold())
                            ->helperText(__('When enabled, imported loans use 0% settlement threshold unless the loans CSV sets settlement_threshold explicitly.')),
                    ])
                    ->visible(fn (): bool => $this->currentStep === 2),
            ]);
    }

    /**
     * @return array<int, array{label: string, description: string}>
     */
    public function wizardStepDefinitions(): array
    {
        return [
            1 => [
                'label' => __('Members'),
                'description' => __('Import member CSV'),
            ],
            2 => [
                'label' => __('Loans'),
                'description' => __('Loans + payments CSV'),
            ],
            3 => [
                'label' => __('Classify'),
                'description' => __('Tag payment rows'),
            ],
            4 => [
                'label' => __('Classification file'),
                'description' => __('Review & download CSV'),
            ],
            5 => [
                'label' => __('Apply'),
                'description' => __('Replay classified rows'),
            ],
        ];
    }

    public function isHistoricalStrategy(): bool
    {
        return true;
    }

    public function goToStep(int $step): void
    {
        $step = max(1, min(self::WIZARD_STEP_COUNT, $step));

        if ($step > 1 && ! $this->membersImported()) {
            Notification::make()
                ->title(__('Import members first'))
                ->body(__('Complete step 1 — import the members CSV before continuing.'))
                ->warning()
                ->send();

            return;
        }

        if ($step > 2 && ! $this->loansImported()) {
            Notification::make()
                ->title(__('Import loans first'))
                ->body(__('Complete step 2 — import the loans CSV before continuing.'))
                ->warning()
                ->send();

            return;
        }

        if ($step > 4 && ! $this->classifiedPaymentsReady) {
            Notification::make()
                ->title(__('Classification file required'))
                ->body(__('Classify payments on step 3 and review the CSV on step 4 before applying.'))
                ->warning()
                ->send();

            return;
        }

        $this->currentStep = $step;

        if (in_array($this->currentStep, [1, 2, 3], true)) {
            $this->refreshUploadDiagnostics();
        }
    }

    public function nextStep(): void
    {
        if (! $this->canAdvanceFromStep($this->currentStep)) {
            return;
        }

        $this->goToStep($this->currentStep + 1);
    }

    public function previousStep(): void
    {
        $this->goToStep($this->currentStep - 1);
    }

    public function canAdvanceFromStep(int $step): bool
    {
        if ($step === 1) {
            if (! $this->membersImported()) {
                Notification::make()
                    ->title(__('Import members first'))
                    ->body(__('Upload and import the members CSV on this step.'))
                    ->warning()
                    ->send();

                return false;
            }

            return true;
        }

        if ($step === 2) {
            if (! $this->loansImported()) {
                Notification::make()
                    ->title(__('Import loans first'))
                    ->body(__('Upload and import the loans CSV on this step.'))
                    ->warning()
                    ->send();

                return false;
            }

            return true;
        }

        if ($step === 3) {
            if (! $this->classifiedPaymentsReady) {
                Notification::make()
                    ->title(__('Classify payments first'))
                    ->body(__('Run classification on this step before continuing.'))
                    ->warning()
                    ->send();

                return false;
            }

            return true;
        }

        if ($step === 4) {
            return $this->classifiedPaymentsReady;
        }

        return false;
    }

    public function classifyPayments(): void
    {
        try {
            $state = $this->workflowState();
        } catch (ValidationException $exception) {
            $this->notifyFormValidationFailed($exception);

            return;
        }

        if (! $this->loansImported()) {
            Notification::make()
                ->title(__('Import loans first'))
                ->body(__('Payment classification uses loan repayment windows from imported loan records. Complete step 2 first.'))
                ->warning()
                ->send();

            return;
        }

        $this->persistUploadSettingsFromState($state);

        $working = $this->workingPaths();

        if (! isset($working['payments_path'])) {
            Notification::make()
                ->title(__('Payments CSV required'))
                ->body(__('Upload the payments CSV in step 2 (or replace it here) before classifying.'))
                ->warning()
                ->send();

            return;
        }

        try {
            if (Storage::disk('local')->exists(LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH)) {
                Storage::disk('local')->delete(LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH);
            }

            Setting::set('legacy_migration', 'classify_status', 'running');
            Setting::set('legacy_migration', 'classify_error', '');
            $this->classificationRunning = true;
            $this->lastKnownClassificationStatus = 'running';
            $this->classifiedPaymentsReady = false;

            $migrationOptions = [
                'cutoff_date' => filled($state['cutoff_date'] ?? null) ? (string) $state['cutoff_date'] : null,
                'default_password' => (string) ($state['default_password'] ?? ''),
                'members_path' => $working['members_path'] ?? null,
                'loans_path' => $working['loans_path'] ?? null,
                'payments_path' => $working['payments_path'],
                'strategy' => 'historical',
                'grace_cycles' => (int) ($state['grace_cycles'] ?? LegacyMigrationGraceCycleSettings::defaultGraceCycles()),
                'loan_funding_strategy' => (string) ($state['loan_funding_strategy'] ?? LegacyMigrationFundingStrategySettings::defaultFundingStrategy()),
                'skip_settlement_threshold' => (bool) ($state['skip_settlement_threshold'] ?? LegacyMigrationSettlementThresholdSettings::defaultSkipSettlementThreshold()),
            ];

            $job = new ClassifyLegacyPaymentsJob(
                migrationOptions: $migrationOptions,
                notifyUserId: auth('tenant')->id(),
            );

            if (app()->environment('testing')) {
                dispatch_sync($job);
                $this->refreshClassificationStateFromSettings();

                Notification::make()
                    ->title($this->classifiedPaymentsReady ? __('Payments classified') : __('Classification finished with errors'))
                    ->body($this->classificationSummaryNotificationBody())
                    ->color($this->classifiedPaymentsReady ? 'success' : 'warning')
                    ->persistent(! $this->classifiedPaymentsReady)
                    ->send();

                $this->currentStep = 4;
            } else {
                ClassifyLegacyPaymentsJob::dispatch($migrationOptions, auth('tenant')->id());

                Notification::make()
                    ->title(__('Classifying payments'))
                    ->body(__('Classification is running in the background. The CSV will be ready on step 4 when finished.'))
                    ->success()
                    ->send();
            }
        } catch (\Throwable $e) {
            report($e);

            $payload = LegacyImportFailureRecorder::buildPayload(
                section: 'classification',
                message: $e->getMessage(),
                exception: $e,
                result: null,
                stage: LegacyImportFailureRecorder::exceptionStage($e, 'classification'),
            );

            Setting::set('legacy_migration', 'classify_status', 'failed');
            Setting::set('legacy_migration', 'classify_error', $e->getMessage());
            Setting::set(
                'legacy_migration',
                LegacyImportFailureRecorder::CLASSIFY_FAILURE_SETTING,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
            $this->classificationRunning = false;
            $this->refreshClassificationStateFromSettings();

            Notification::make()
                ->title(__('Classification failed'))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function downloadClassifiedPayments(): StreamedResponse
    {
        abort_unless($this->classifiedPaymentsReady && Storage::disk('local')->exists(self::CLASSIFIED_PAYMENTS_PATH), 404);

        return Storage::disk('local')->download(
            self::CLASSIFIED_PAYMENTS_PATH,
            'legacy-payments-classified.csv',
            [...Utf8CsvStream::downloadHeaders()],
        );
    }

    public function runMigration(bool $dryRun = false): void
    {
        try {
            $state = $this->workflowState();
        } catch (ValidationException $exception) {
            $this->notifyFormValidationFailed($exception);

            return;
        }

        if (! $this->classifiedPaymentsReady) {
            Notification::make()
                ->title(__('Classification file required'))
                ->body(__('Complete steps 3 and 4 before applying the migration.'))
                ->warning()
                ->send();

            return;
        }

        if (! $dryRun && Setting::get('legacy_migration', 'run_status') === 'running') {
            Notification::make()
                ->title(__('Migration already running'))
                ->body(__('A migration is already in progress. This page will update when it finishes.'))
                ->warning()
                ->send();

            return;
        }

        $this->persistUploadSettingsFromState($state);

        $working = $this->workingPaths();

        $options = [
            'loans_path' => $working['loans_path'] ?? null,
            'classified_payments_path' => Storage::disk('local')->exists(self::CLASSIFIED_PAYMENTS_PATH)
                ? Storage::disk('local')->path(self::CLASSIFIED_PAYMENTS_PATH)
                : null,
            'strategy' => 'historical',
            'grace_cycles' => (int) ($state['grace_cycles'] ?? LegacyMigrationGraceCycleSettings::defaultGraceCycles()),
        ];

        if ($dryRun) {
            try {
                $this->lastRun = app(LegacyMigrationOrchestrator::class)->dryRunClassifiedApply($options);
                $payments = $this->lastRun['payments'] ?? [];

                Notification::make()
                    ->title(__('Dry run complete'))
                    ->body(__('Would post :contributions contribution(s) and :repayments loan repayment(s).', [
                        'contributions' => $payments['contributions'] ?? 0,
                        'repayments' => $payments['loan_repayments'] ?? 0,
                    ]))
                    ->success()
                    ->send();
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
            Setting::set('legacy_migration', 'run_status', 'running');
            Setting::set('legacy_migration', 'last_error', '');
            Setting::set('legacy_migration', LegacyImportFailureRecorder::APPLY_FAILURE_SETTING, '');
            $this->migrationRunning = true;
            $this->lastKnownMigrationStatus = 'running';
            $this->migrationResultsPanel = null;

            if (app()->environment('testing')) {
                dispatch_sync(new RunLegacyMigrationPaymentsJob($options, [], auth('tenant')->id()));
                $this->refreshMigrationStateFromSettings();

                $status = (string) Setting::get('legacy_migration', 'run_status', 'idle');

                Notification::make()
                    ->title($status === 'failed' ? __('Migration failed') : __('Migration complete'))
                    ->body($status === 'failed'
                        ? (string) ($this->migrationResultsPanel['message'] ?? __('Migration failed'))
                        : null)
                    ->color($status === 'failed' ? 'danger' : 'success')
                    ->send();
            } else {
                RunLegacyMigrationPaymentsJob::dispatch($options, [], auth('tenant')->id());

                Notification::make()
                    ->title(__('Applying migration'))
                    ->body(__('Payment rows are being replayed in the background. Results will appear below when finished.'))
                    ->success()
                    ->send();
            }
        } catch (\Throwable $e) {
            report($e);

            $payload = LegacyImportFailureRecorder::buildPayload(
                section: 'payments',
                message: $e->getMessage(),
                exception: $e,
                result: null,
                stage: LegacyImportFailureRecorder::exceptionStage($e, 'apply'),
            );

            Setting::set('legacy_migration', 'run_status', 'failed');
            Setting::set('legacy_migration', 'last_error', $e->getMessage());
            Setting::set(
                'legacy_migration',
                LegacyImportFailureRecorder::APPLY_FAILURE_SETTING,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
            $this->migrationRunning = false;
            $this->migrationLastError = $e->getMessage();
            $this->refreshMigrationStateFromSettings();

            Notification::make()
                ->title(__('Migration failed'))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowState(bool $requirePassword = false): array
    {
        $rules = [
            'data.cutoff_date' => ['required', 'date'],
            'data.slash_date_format' => ['required', 'string'],
        ];

        if ($requirePassword) {
            $rules['data.default_password'] = ['required', 'string', 'min:8'];
        }

        $this->validate($rules);

        return $this->data;
    }

    private function refreshClassificationStateFromSettings(): void
    {
        $status = (string) Setting::get('legacy_migration', 'classify_status', 'idle');
        $this->classificationRunning = $status === 'running';
        $this->classifiedPaymentsReady = Storage::disk('local')->exists(self::CLASSIFIED_PAYMENTS_PATH);
        $this->classificationStats = null;
        $this->classificationErrors = [];
        $this->classificationResultsPanel = null;

        $statsJson = Setting::get('legacy_migration', 'classify_stats');

        if (is_string($statsJson) && $statsJson !== '') {
            $decoded = json_decode($statsJson, true);

            if (is_array($decoded)) {
                $this->classificationStats = $decoded;
            }
        }

        $errorsJson = Setting::get('legacy_migration', 'classify_errors');

        if (is_string($errorsJson) && $errorsJson !== '') {
            $decoded = json_decode($errorsJson, true);

            if (is_array($decoded)) {
                $this->classificationErrors = LegacyImportFailureRecorder::normalizeErrors($decoded);
            }
        }

        $failure = $this->decodedImportResult(LegacyImportFailureRecorder::CLASSIFY_FAILURE_SETTING);
        $errorMessage = (string) Setting::get('legacy_migration', 'classify_error', '');
        $stats = $this->classificationStats ?? [];
        $errors = $this->classificationErrors;

        if ($failure !== []) {
            $errors = LegacyImportFailureRecorder::normalizeErrors($failure['errors'] ?? $errors);
            $errorMessage = (string) ($failure['message'] ?? $errorMessage);
        }

        $panelStatus = match (true) {
            $status === 'failed' => 'failed',
            $errors !== [] || (int) ($stats['failed'] ?? 0) > 0 => 'warning',
            $status === 'completed' || $stats !== [] => 'completed',
            default => 'idle',
        };

        $this->classificationResultsPanel = LegacyImportFailureRecorder::finalizePanel([
            'wire_key' => 'classification-results',
            'status' => $panelStatus,
            'title_failed' => __('Classification failed'),
            'title_warning' => __('Classification finished with warnings'),
            'title_success' => __('Last classification result'),
            'message' => $errorMessage !== '' ? $errorMessage : null,
            'stage' => $failure['stage'] ?? ($status === 'failed' ? 'classification' : null),
            'location' => $failure['location'] ?? null,
            'stats' => [
                ['label' => __('Contributions'), 'value' => (int) ($stats['contributions'] ?? $stats['contribution'] ?? $failure['contributions'] ?? 0)],
                ['label' => __('Loan repayments'), 'value' => (int) ($stats['loan_repayments'] ?? $stats['loan_repayment'] ?? $failure['loan_repayments'] ?? 0)],
                ['label' => __('Reclassified as contribution'), 'value' => (int) ($stats['reclassified_as_contribution'] ?? 0)],
                ['label' => __('Failed rows'), 'value' => (int) ($stats['failed'] ?? $failure['failed'] ?? count($errors))],
            ],
            'errors' => $errors,
            'errors_total' => (int) ($failure['errors_total'] ?? count($errors)),
            'errors_truncated' => (bool) ($failure['errors_truncated'] ?? false),
        ]);
    }

    private function refreshMigrationStateFromSettings(): void
    {
        $status = (string) Setting::get('legacy_migration', 'run_status', 'idle');
        $this->migrationRunning = $status === 'running';
        $this->migrationLastError = (string) Setting::get('legacy_migration', 'last_error', '');
        $this->lastRun = null;
        $this->migrationResultsPanel = null;

        $lastRunJson = Setting::get('legacy_migration', 'last_run');

        if (is_string($lastRunJson) && $lastRunJson !== '') {
            $decoded = json_decode($lastRunJson, true);

            if (is_array($decoded)) {
                $this->lastRun = $decoded;
            }
        }

        $failure = $this->decodedImportResult(LegacyImportFailureRecorder::APPLY_FAILURE_SETTING);
        $payments = is_array($this->lastRun['payments'] ?? null) ? $this->lastRun['payments'] : [];
        $errors = LegacyImportFailureRecorder::normalizeErrors($payments['errors'] ?? []);
        $errorMessage = $this->migrationLastError;

        if ($failure !== []) {
            $errors = LegacyImportFailureRecorder::normalizeErrors($failure['errors'] ?? $errors);
            $errorMessage = (string) ($failure['message'] ?? $errorMessage);
        }

        $panelStatus = match (true) {
            $status === 'failed' => 'failed',
            $errors !== [] || (int) ($payments['failed'] ?? 0) > 0 => 'warning',
            $status === 'completed' || $payments !== [] => 'completed',
            default => 'idle',
        };

        $this->migrationResultsPanel = LegacyImportFailureRecorder::finalizePanel([
            'wire_key' => 'apply-migration-results',
            'status' => $panelStatus,
            'title_failed' => __('Migration failed'),
            'title_warning' => __('Migration finished with warnings'),
            'title_success' => __('Last apply result'),
            'message' => filled($errorMessage) ? $errorMessage : null,
            'stage' => $failure['stage'] ?? ($status === 'failed' ? 'apply' : null),
            'location' => $failure['location'] ?? null,
            'stats' => [
                ['label' => __('Contributions'), 'value' => (int) ($payments['contributions'] ?? $failure['contributions'] ?? 0)],
                ['label' => __('Loan repayments'), 'value' => (int) ($payments['loan_repayments'] ?? $failure['loan_repayments'] ?? 0)],
                ['label' => __('Reclassified as contribution'), 'value' => (int) ($payments['reclassified_as_contribution'] ?? 0)],
                ['label' => __('Failed rows'), 'value' => (int) ($payments['failed'] ?? $failure['failed'] ?? count($errors))],
            ],
            'errors' => $errors,
            'errors_total' => (int) ($failure['errors_total'] ?? $payments['errors_total'] ?? count($errors)),
            'errors_truncated' => (bool) ($failure['errors_truncated'] ?? $payments['errors_truncated'] ?? false),
        ]);
    }

    private function invalidateMembersImportReady(): void
    {
        Setting::set('legacy_migration', self::SETTING_MEMBERS_IMPORTED, '0');
        Setting::set('legacy_migration', self::SETTING_MEMBERS_IMPORT_STATUS, 'idle');
        Setting::set('legacy_migration', 'members_import_error', '');
        Setting::set('legacy_migration', LegacyImportFailureRecorder::MEMBERS_FAILURE_SETTING, '');
        Setting::set('legacy_migration', 'members_import_result', '');
        $this->membersImportRunning = false;
        $this->lastKnownMembersImportStatus = 'idle';
        $this->membersResultsPanel = null;
    }

    private function invalidateLoansImportReady(): void
    {
        Setting::set('legacy_migration', self::SETTING_LOANS_IMPORTED, '0');
        Setting::set('legacy_migration', self::SETTING_LOANS_IMPORT_STATUS, 'idle');
        Setting::set('legacy_migration', 'loans_import_error', '');
        Setting::set('legacy_migration', LegacyImportFailureRecorder::LOANS_FAILURE_SETTING, '');
        Setting::set('legacy_migration', 'loans_import_result', '');
        $this->loansImportRunning = false;
        $this->lastKnownLoansImportStatus = 'idle';
        $this->loansResultsPanel = null;
    }

    private function invalidateClassificationAfterUploadChange(): void
    {
        Setting::set('legacy_migration', 'classify_status', 'idle');
        Setting::set('legacy_migration', 'classify_error', '');
        Setting::set('legacy_migration', LegacyImportFailureRecorder::CLASSIFY_FAILURE_SETTING, '');
        Setting::set('legacy_migration', 'classify_errors', '');
        Setting::set('legacy_migration', 'classify_stats', '');
        $this->classificationRunning = false;
        $this->classifiedPaymentsReady = false;
        $this->classificationStats = null;
        $this->classificationErrors = [];
        $this->classificationResultsPanel = null;
        $this->lastKnownClassificationStatus = 'idle';

        if (Storage::disk('local')->exists(self::CLASSIFIED_PAYMENTS_PATH)) {
            Storage::disk('local')->delete(self::CLASSIFIED_PAYMENTS_PATH);
        }
    }

    private function classificationSummaryNotificationBody(): string
    {
        $stats = $this->classificationStats ?? [];

        $body = __('Contributions: :c · Loan repayments: :l · Reclassified: :r · Failed: :failed', [
            'c' => $stats['contributions'] ?? $stats['contribution'] ?? 0,
            'l' => $stats['loan_repayments'] ?? $stats['loan_repayment'] ?? 0,
            'r' => $stats['reclassified_as_contribution'] ?? 0,
            'failed' => $stats['failed'] ?? 0,
        ]);

        if ($this->classificationErrors !== []) {
            $body .= "\n\n".implode("\n", array_slice($this->classificationErrors, 0, 5));
        }

        if ($this->classifiedPaymentsReady) {
            $body .= "\n\n".__('Download the classified CSV on step 4, then apply the migration on step 5.');
        }

        return $body;
    }

    public function importMembers(): void
    {
        try {
            $state = $this->workflowState(requirePassword: true);
        } catch (ValidationException $exception) {
            $this->notifyFormValidationFailed($exception);

            return;
        }

        $this->persistUploadSettingsFromState($state);

        $working = $this->workingPaths();

        if (! isset($working['members_path'])) {
            Notification::make()
                ->title(__('Members CSV required'))
                ->body(__('Upload the members CSV on this step first.'))
                ->warning()
                ->send();

            return;
        }

        $cutoff = filled($state['cutoff_date'] ?? null) ? (string) $state['cutoff_date'] : null;

        $options = [
            'cutoff_date' => $cutoff,
            'default_password' => (string) ($state['default_password'] ?? ''),
            'members_path' => $working['members_path'],
        ];

        try {
            Setting::set('legacy_migration', self::SETTING_MEMBERS_IMPORT_STATUS, 'running');
            Setting::set('legacy_migration', 'members_import_error', '');
            Setting::set('legacy_migration', LegacyImportFailureRecorder::MEMBERS_FAILURE_SETTING, '');
            $this->membersImportRunning = true;
            $this->lastKnownMembersImportStatus = 'running';
            $this->membersResultsPanel = null;

            $job = new ImportLegacyMembersJob($options, $cutoff, auth('tenant')->id());

            if (app()->environment('testing')) {
                dispatch_sync($job);
                $this->refreshMembersImportStateFromSettings();

                $status = (string) Setting::get('legacy_migration', self::SETTING_MEMBERS_IMPORT_STATUS, 'idle');
                $members = $this->decodedImportResult('members_import_result')['members'] ?? [];

                if ($status === 'failed') {
                    Notification::make()
                        ->title(__('Import failed'))
                        ->body((string) ($this->membersResultsPanel['message'] ?? __('Import failed')))
                        ->danger()
                        ->persistent()
                        ->send();
                } else {
                    $failed = (int) ($members['failed'] ?? 0);
                    $hasErrors = $failed > 0 || count($members['errors'] ?? []) > 0;

                    Notification::make()
                        ->title($hasErrors ? __('Members imported with warnings') : __('Members imported'))
                        ->body(__('Members created: :created · Skipped: :skipped · Failed: :failed', [
                            'created' => $members['created'] ?? 0,
                            'skipped' => $members['skipped'] ?? 0,
                            'failed' => $failed,
                        ]))
                        ->color($hasErrors ? 'warning' : 'success')
                        ->send();
                }
            } else {
                ImportLegacyMembersJob::dispatch($options, $cutoff, auth('tenant')->id());

                Notification::make()
                    ->title(__('Importing members'))
                    ->body(__('Import is running in the background. This page will update when finished.'))
                    ->success()
                    ->send();
            }
        } catch (\Throwable $e) {
            report($e);

            $this->persistMembersImportFailure($e);
            $this->refreshMembersImportStateFromSettings();

            Notification::make()
                ->title(__('Import failed'))
                ->body($e->getMessage() !== '' ? $e->getMessage() : __('Import failed'))
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function importLoans(): void
    {
        try {
            $state = $this->workflowState();
        } catch (ValidationException $exception) {
            $this->notifyFormValidationFailed($exception);

            return;
        }

        if (! $this->membersImported()) {
            Notification::make()
                ->title(__('Import members first'))
                ->body(__('Complete step 1 before importing loans.'))
                ->warning()
                ->send();

            return;
        }

        $this->persistUploadSettingsFromState($state);

        $working = $this->workingPaths();

        if (! isset($working['loans_path'])) {
            Notification::make()
                ->title(__('Loans CSV required'))
                ->body(__('Upload the loans CSV on this step first.'))
                ->warning()
                ->send();

            return;
        }

        if (! isset($working['payments_path'])) {
            Notification::make()
                ->title(__('Payments CSV required'))
                ->body(__('Upload the payments CSV on this step too. It is used to calculate each member fund top-up on the loan disbursement date.'))
                ->warning()
                ->send();

            return;
        }

        $options = [
            'loans_path' => $working['loans_path'],
            'payments_path' => $working['payments_path'],
            'strategy' => 'historical',
            'grace_cycles' => (int) ($state['grace_cycles'] ?? LegacyMigrationGraceCycleSettings::defaultGraceCycles()),
            'loan_funding_strategy' => (string) ($state['loan_funding_strategy'] ?? LegacyMigrationFundingStrategySettings::defaultFundingStrategy()),
            'skip_settlement_threshold' => (bool) ($state['skip_settlement_threshold'] ?? LegacyMigrationSettlementThresholdSettings::defaultSkipSettlementThreshold()),
        ];

        try {
            Setting::set('legacy_migration', self::SETTING_LOANS_IMPORT_STATUS, 'running');
            Setting::set('legacy_migration', 'loans_import_error', '');
            Setting::set('legacy_migration', LegacyImportFailureRecorder::LOANS_FAILURE_SETTING, '');
            $this->loansImportRunning = true;
            $this->lastKnownLoansImportStatus = 'running';
            $this->loansResultsPanel = null;

            $job = new ImportLegacyLoansJob($options, auth('tenant')->id());

            if (app()->environment('testing')) {
                dispatch_sync($job);
                $this->refreshLoansImportStateFromSettings();

                $status = (string) Setting::get('legacy_migration', self::SETTING_LOANS_IMPORT_STATUS, 'idle');
                $loans = $this->decodedImportResult('loans_import_result')['loans'] ?? [];

                if ($status === 'failed') {
                    Notification::make()
                        ->title(__('Import failed'))
                        ->body((string) ($this->loansResultsPanel['message'] ?? __('Import failed')))
                        ->danger()
                        ->persistent()
                        ->send();
                } else {
                    $failed = (int) ($loans['failed'] ?? 0);
                    $hasErrors = $failed > 0 || count($loans['errors'] ?? []) > 0;

                    Notification::make()
                        ->title($hasErrors ? __('Loans imported with warnings') : __('Loans imported'))
                        ->body(__('Loans created: :loans · Failed: :failed', [
                            'loans' => $loans['created'] ?? 0,
                            'failed' => $failed,
                        ]))
                        ->color($hasErrors ? 'warning' : 'success')
                        ->send();
                }
            } else {
                ImportLegacyLoansJob::dispatch($options, auth('tenant')->id());

                Notification::make()
                    ->title(__('Importing loans'))
                    ->body(__('Import is running in the background. This page will update when finished.'))
                    ->success()
                    ->send();
            }
        } catch (\Throwable $e) {
            report($e);

            $this->persistLoansImportFailure($e);
            $this->refreshLoansImportStateFromSettings();

            Notification::make()
                ->title(__('Import failed'))
                ->body($e->getMessage() !== '' ? $e->getMessage() : __('Import failed'))
                ->danger()
                ->persistent()
                ->send();
        }
    }

    private function refreshMembersImportStateFromSettings(): void
    {
        $status = (string) Setting::get('legacy_migration', self::SETTING_MEMBERS_IMPORT_STATUS, 'idle');
        $this->membersImportRunning = $status === 'running';
        $this->membersResultsPanel = $this->hydrateResultsPanel(
            wireKey: 'members-import-results',
            status: $status,
            failureSetting: LegacyImportFailureRecorder::MEMBERS_FAILURE_SETTING,
            errorSetting: 'members_import_error',
            resultSetting: 'members_import_result',
            resultSection: 'members',
            titleFailed: __('Members import failed'),
            titleWarning: __('Members imported with warnings'),
            titleSuccess: __('Last members import result'),
            statsFrom: fn (array $row): array => [
                ['label' => __('Created'), 'value' => (int) ($row['created'] ?? 0)],
                ['label' => __('Skipped'), 'value' => (int) ($row['skipped'] ?? 0)],
                ['label' => __('Failed rows'), 'value' => (int) ($row['failed'] ?? 0)],
            ],
        );
    }

    private function refreshLoansImportStateFromSettings(): void
    {
        $status = (string) Setting::get('legacy_migration', self::SETTING_LOANS_IMPORT_STATUS, 'idle');
        $this->loansImportRunning = $status === 'running';
        $this->loansResultsPanel = $this->hydrateResultsPanel(
            wireKey: 'loans-import-results',
            status: $status,
            failureSetting: LegacyImportFailureRecorder::LOANS_FAILURE_SETTING,
            errorSetting: 'loans_import_error',
            resultSetting: 'loans_import_result',
            resultSection: 'loans',
            titleFailed: __('Loans import failed'),
            titleWarning: __('Loans imported with warnings'),
            titleSuccess: __('Last loans import result'),
            statsFrom: fn (array $row): array => [
                ['label' => __('Created'), 'value' => (int) ($row['created'] ?? 0)],
                ['label' => __('Failed rows'), 'value' => (int) ($row['failed'] ?? 0)],
            ],
        );
    }

    /**
     * @param  callable(array<string, mixed>): list<array{label: string, value: int|string}>  $statsFrom
     * @return array<string, mixed>|null
     */
    private function hydrateResultsPanel(
        string $wireKey,
        string $status,
        string $failureSetting,
        string $errorSetting,
        string $resultSetting,
        string $resultSection,
        string $titleFailed,
        string $titleWarning,
        string $titleSuccess,
        callable $statsFrom,
    ): ?array {
        $failure = $this->decodedImportResult($failureSetting);
        $resultRoot = $this->decodedImportResult($resultSetting);
        $section = is_array($resultRoot[$resultSection] ?? null) ? $resultRoot[$resultSection] : [];

        if ($failure !== []) {
            return LegacyImportFailureRecorder::finalizePanel([
                'wire_key' => $wireKey,
                'status' => 'failed',
                'title_failed' => $titleFailed,
                'title_warning' => $titleWarning,
                'title_success' => $titleSuccess,
                'message' => (string) ($failure['message'] ?? Setting::get('legacy_migration', $errorSetting, '')),
                'stage' => (string) ($failure['stage'] ?? ''),
                'location' => isset($failure['location']) ? (string) $failure['location'] : null,
                'stats' => $statsFrom($failure),
                'errors' => $failure['errors'] ?? [],
                'errors_total' => (int) ($failure['errors_total'] ?? count($failure['errors'] ?? [])),
                'errors_truncated' => (bool) ($failure['errors_truncated'] ?? false),
            ]);
        }

        $errorMessage = (string) Setting::get('legacy_migration', $errorSetting, '');
        $errors = LegacyImportFailureRecorder::normalizeErrors($section['errors'] ?? []);
        $panelStatus = $status;

        if ($status === 'failed') {
            $panelStatus = 'failed';
            if ($errors === [] && $errorMessage !== '') {
                $errors = [$errorMessage];
            }
        } elseif ($errors !== [] || (int) ($section['failed'] ?? 0) > 0) {
            $panelStatus = 'warning';
        } elseif ($status === 'completed' || $section !== []) {
            $panelStatus = 'completed';
        } else {
            $panelStatus = 'idle';
        }

        if ($panelStatus === 'idle' && $errorMessage === '' && $errors === [] && $section === []) {
            return null;
        }

        return LegacyImportFailureRecorder::finalizePanel([
            'wire_key' => $wireKey,
            'status' => $panelStatus,
            'title_failed' => $titleFailed,
            'title_warning' => $titleWarning,
            'title_success' => $titleSuccess,
            'message' => $errorMessage !== '' ? $errorMessage : null,
            'stage' => null,
            'location' => null,
            'stats' => $statsFrom($section !== [] ? $section : ['failed' => count($errors)]),
            'errors' => $errors,
            'errors_total' => (int) ($section['errors_total'] ?? count($errors)),
            'errors_truncated' => (bool) ($section['errors_truncated'] ?? false),
        ]);
    }

    private function persistStepImportFailure(
        string $section,
        string $statusSetting,
        string $errorSetting,
        string $failureSetting,
        string $resultSetting,
        \Throwable $exception,
        string $defaultStage = 'import',
    ): void {
        $result = $exception instanceof IncompleteLegacyImportException
            ? $exception->result
            : null;

        $payload = LegacyImportFailureRecorder::buildPayload(
            section: $section,
            message: $exception->getMessage(),
            exception: $exception,
            result: $result,
            stage: LegacyImportFailureRecorder::exceptionStage($exception, $defaultStage),
        );

        Setting::set('legacy_migration', $statusSetting, 'failed');
        Setting::set('legacy_migration', $errorSetting, $exception->getMessage());
        Setting::set(
            'legacy_migration',
            $failureSetting,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        if ($result !== null) {
            Setting::set(
                'legacy_migration',
                $resultSetting,
                json_encode(
                    LegacyMigrationOrchestrator::summarizeForDisplay([$section => $result]),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            );
        }
    }

    private function persistMembersImportFailure(\Throwable $exception): void
    {
        $this->persistStepImportFailure(
            section: 'members',
            statusSetting: self::SETTING_MEMBERS_IMPORT_STATUS,
            errorSetting: 'members_import_error',
            failureSetting: LegacyImportFailureRecorder::MEMBERS_FAILURE_SETTING,
            resultSetting: 'members_import_result',
            exception: $exception,
        );
        $this->membersImportRunning = false;
    }

    private function persistLoansImportFailure(\Throwable $exception): void
    {
        $this->persistStepImportFailure(
            section: 'loans',
            statusSetting: self::SETTING_LOANS_IMPORT_STATUS,
            errorSetting: 'loans_import_error',
            failureSetting: LegacyImportFailureRecorder::LOANS_FAILURE_SETTING,
            resultSetting: 'loans_import_result',
            exception: $exception,
        );
        $this->loansImportRunning = false;
    }

    public function membersImported(): bool
    {
        return Setting::get('legacy_migration', self::SETTING_MEMBERS_IMPORTED) === '1';
    }

    public function loansImported(): bool
    {
        return Setting::get('legacy_migration', self::SETTING_LOANS_IMPORTED) === '1';
    }

    public function membersLoansImported(): bool
    {
        return $this->membersImported() && $this->loansImported();
    }

    public function hasWorkingMembersCsv(): bool
    {
        return Storage::disk('local')->exists(LegacyMigrationWorkingCopy::MEMBERS_RELATIVE);
    }

    public function hasWorkingLoansCsv(): bool
    {
        return Storage::disk('local')->exists(LegacyMigrationWorkingCopy::LOANS_RELATIVE);
    }

    public function hasWorkingPaymentsCsv(): bool
    {
        return Storage::disk('local')->exists(LegacyMigrationWorkingCopy::PAYMENTS_RELATIVE);
    }

    /**
     * @return array{members_path?: string, loans_path?: string, payments_path?: string}
     */
    private function workingPaths(): array
    {
        return app(LegacyMigrationWorkingCopy::class)->existingPaths();
    }

    public function updatedPendingMembersCsv(): void
    {
        $this->persistLivewireCsvUpload('pendingMembersCsv', 'members');
    }

    public function updatedPendingLoansCsv(): void
    {
        $this->persistLivewireCsvUpload('pendingLoansCsv', 'loans');
    }

    public function updatedPendingPaymentsCsv(): void
    {
        $this->persistLivewireCsvUpload('pendingPaymentsCsv', 'payments');
    }

    private function persistLivewireCsvUpload(string $property, string $kind): void
    {
        $file = $this->{$property};

        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        $this->validate([
            $property => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
        ]);

        try {
            app(LegacyMigrationWorkingCopy::class)->storeContents($kind, $file->get());
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(__('Upload failed'))
                ->body($exception->getMessage() !== '' ? $exception->getMessage() : __('Could not save the CSV file.'))
                ->danger()
                ->persistent()
                ->send();

            $this->{$property} = null;

            return;
        }

        $this->{$property} = null;
        $this->afterWorkingCsvReplaced($kind);

        Notification::make()
            ->title(__('CSV uploaded'))
            ->body(match ($kind) {
                'members' => __('Members CSV is ready to import.'),
                'loans' => __('Loans CSV is ready to import.'),
                'payments' => __('Payments CSV is ready for classification.'),
                default => __('File saved.'),
            })
            ->success()
            ->send();
    }

    private function afterWorkingCsvReplaced(string $kind): void
    {
        if ($kind === 'members') {
            $this->invalidateMembersImportReady();
            $this->invalidateLoansImportReady();
            $this->invalidateClassificationAfterUploadChange();
        } elseif ($kind === 'loans') {
            $this->invalidateLoansImportReady();
            $this->invalidateClassificationAfterUploadChange();
        } elseif ($kind === 'payments') {
            $this->invalidateClassificationAfterUploadChange();
        }

        $this->refreshUploadDiagnostics();

        if ($kind === 'loans' && ($this->uploadDiagnostics['loans']['has_loan_id'] ?? false) === false) {
            Notification::make()
                ->title(__('Loans CSV is missing loan_id'))
                ->body(__('Add a loan_id column (or Loan Id header) so legacy loan numbers are preserved during classification and import.'))
                ->warning()
                ->send();
        }
    }

    private function refreshUploadDiagnostics(): void
    {
        $this->uploadDiagnostics = app(LegacyMigrationUploadDiagnostics::class)->summarize();
    }

    private function notifyFormValidationFailed(ValidationException $exception): void
    {
        Notification::make()
            ->title(__('Check the form'))
            ->body(collect($exception->errors())->flatten()->first() ?? __('Fix the highlighted fields and try again.'))
            ->danger()
            ->send();
    }

    private function persistUploadSettingsFromState(array $state): void
    {
        LegacyMigrationDateFormatSettings::saveSlashDateFormat(
            (string) ($state['slash_date_format'] ?? LegacyMigrationDateFormatSettings::defaultSlashDateFormat()),
        );

        LegacyMigrationGraceCycleSettings::saveGraceCycles(
            (int) ($state['grace_cycles'] ?? LegacyMigrationGraceCycleSettings::defaultGraceCycles()),
        );

        LegacyMigrationFundingStrategySettings::saveFundingStrategy(
            (string) ($state['loan_funding_strategy'] ?? LegacyMigrationFundingStrategySettings::defaultFundingStrategy()),
        );

        LegacyMigrationSettlementThresholdSettings::saveSkipSettlementThreshold(
            (bool) ($state['skip_settlement_threshold'] ?? LegacyMigrationSettlementThresholdSettings::defaultSkipSettlementThreshold()),
        );
    }
}
