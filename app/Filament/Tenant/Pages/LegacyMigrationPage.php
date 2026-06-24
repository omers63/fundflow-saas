<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Support\Action;
use App\Filament\Tenant\Concerns\EmbedsAsAuditWorkspacePanel;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Jobs\Tenant\ClassifyLegacyPaymentsJob;
use App\Jobs\Tenant\RunLegacyMigrationPaymentsJob;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\LegacyMigration\LegacyMigrationOrchestrator;
use App\Services\LegacyMigration\LegacyMigrationPreviewService;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use App\Support\BusinessDay;
use App\Support\FilamentStoredUploadPath;
use App\Support\LegacyMigrationDateFormatSettings;
use App\Support\LegacyMigrationFundingStrategySettings;
use App\Support\LegacyMigrationGraceCycleSettings;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
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

    public bool $classificationRunning = false;

    public bool $migrationRunning = false;

    public ?string $migrationLastError = null;

    public ?string $lastKnownMigrationStatus = null;

    public ?string $lastKnownClassificationStatus = null;

    /** @var array{members: ?string, loans: ?string, payments: ?string}|null */
    public ?array $cachedUploadPaths = null;

    private const CLASSIFIED_PAYMENTS_PATH = LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH;

    public const WIZARD_STEP_COUNT = 4;

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
            'slash_date_format' => LegacyMigrationDateFormatSettings::slashDateFormat(),
            'grace_cycles' => LegacyMigrationGraceCycleSettings::graceCycles(),
            'loan_funding_strategy' => LegacyMigrationFundingStrategySettings::fundingStrategy(),
        ]);

        $this->classifiedPaymentsReady = Storage::disk('local')->exists(self::CLASSIFIED_PAYMENTS_PATH);
        $this->refreshMigrationStateFromSettings();
        $this->refreshClassificationStateFromSettings();
        $this->lastKnownClassificationStatus = (string) Setting::get('legacy_migration', 'classify_status', 'idle');
        $this->lastKnownMigrationStatus = (string) Setting::get('legacy_migration', 'run_status', 'idle');
    }

    public function pollClassificationStatus(): void
    {
        if ($this->currentStep !== 3) {
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
                ->longRunning()
                ->longRunningMessage(__('Importing members, loans, and payments. This can take a few minutes.'))
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
                Section::make(__('Migration strategy'))
                    ->description(__('Snapshot is fastest when you have opening balances. Historical replays classified payment rows.'))
                    ->schema([
                        Radio::make('strategy')
                            ->label(__('Migration strategy'))
                            ->options([
                                'snapshot' => __('Snapshot (recommended) — opening balances at cut-off; skip ambiguous payments'),
                                'historical' => __('Historical — also import classified payment rows after members and loans'),
                            ])
                            ->default('snapshot')
                            ->live(),
                    ])
                    ->visible(fn(): bool => $this->currentStep === 1),
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
                        Select::make('grace_cycles')
                            ->label(__('Grace cycles before first repayment'))
                            ->options(LegacyMigrationGraceCycleSettings::graceCycleOptions())
                            ->default(LegacyMigrationGraceCycleSettings::defaultGraceCycles())
                            ->required()
                            ->native(false)
                            ->helperText(__('Payments before the first repayment cycle are classified as contributions. Imported loans use the same grace setting.')),
                        Select::make('loan_funding_strategy')
                            ->label(__('Loan funding strategy'))
                            ->options(LegacyMigrationFundingStrategySettings::fundingStrategyOptions())
                            ->default(LegacyMigrationFundingStrategySettings::defaultFundingStrategy())
                            ->required()
                            ->native(false)
                            ->helperText(__('Used when the loans CSV omits member_portion and master_portion. Member fund balance comes from imported opening balances.')),
                        TextInput::make('default_password')
                            ->label(__('Default member password'))
                            ->password()
                            ->revealable()
                            ->helperText(__('Required only when you run the import. Used when the members CSV password column is empty.')),
                    ])
                    ->visible(fn(): bool => $this->currentStep === 2),
                Section::make(__('CSV files'))
                    ->description(__('Wait until each upload finishes before continuing.'))
                    ->schema([
                        FileUpload::make('members_csv')
                            ->label(__('Members CSV'))
                            ->disk('local')
                            ->directory('legacy-migration')
                            ->maxFiles(1)
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                            ->helperText(__('Required. One row per member with cutoff_cash_balance and cutoff_fund_balance.')),
                        FileUpload::make('loans_csv')
                            ->label(__('Loans CSV'))
                            ->disk('local')
                            ->directory('legacy-migration')
                            ->maxFiles(1)
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                            ->helperText(__('Optional. Active loans with paid_installments_count and total_amount_repaid.')),
                        FileUpload::make('payments_csv')
                            ->label(__('Payments CSV'))
                            ->disk('local')
                            ->directory('legacy-migration')
                            ->maxFiles(1)
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                            ->visible(fn(): bool => $this->isHistoricalStrategy())
                            ->helperText(__('Required for historical strategy. Classify rows on the next step before importing.')),
                    ])
                    ->visible(fn(): bool => $this->currentStep === 2),
            ]);
    }

    /**
     * @return array<int, array{label: string, description: string}>
     */
    public function wizardStepDefinitions(): array
    {
        return [
            1 => [
                'label' => __('Strategy'),
                'description' => __('Choose snapshot or payment history'),
            ],
            2 => [
                'label' => __('Upload'),
                'description' => __('Settings and CSV files'),
            ],
            3 => [
                'label' => __('Review'),
                'description' => __('Validate and classify payments'),
            ],
            4 => [
                'label' => __('Import'),
                'description' => __('Dry run, then import'),
            ],
        ];
    }

    public function isHistoricalStrategy(): bool
    {
        return ($this->data['strategy'] ?? 'snapshot') === 'historical';
    }

    public function goToStep(int $step): void
    {
        if ($this->currentStep === 2 && $step >= 3) {
            $this->tryCacheUploadPaths();
        }

        $this->currentStep = max(1, min(self::WIZARD_STEP_COUNT, $step));
    }

    public function nextStep(): void
    {
        if (!$this->canAdvanceFromStep($this->currentStep)) {
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
            return true;
        }

        if ($step === 2) {
            try {
                $this->workflowState(requirePassword: false, requireMembersUpload: true);
            } catch (ValidationException $exception) {
                $this->notifyFormValidationFailed($exception);

                return false;
            }

            if ($this->isHistoricalStrategy()) {
                $paths = $this->resolveWorkflowPaths($this->data);

                if ($paths['payments'] === null) {
                    Notification::make()
                        ->title(__('Payments CSV required'))
                        ->body(__('Upload a payments CSV for historical migration, or switch to snapshot strategy.'))
                        ->warning()
                        ->send();

                    return false;
                }
            }

            return true;
        }

        if ($step === 3) {
            return true;
        }

        return false;
    }

    public function previewMigration(): void
    {
        try {
            $state = $this->workflowState();
        } catch (ValidationException $exception) {
            $this->notifyFormValidationFailed($exception);

            return;
        }

        $this->persistUploadSettingsFromState($state);

        $paths = $this->resolveWorkflowPaths($state);

        if ($paths['members'] === null) {
            Notification::make()
                ->title(__('Members CSV required'))
                ->body(__('Upload a members CSV on the Upload step and wait until it finishes uploading.'))
                ->warning()
                ->send();

            return;
        }

        $previewService = app(LegacyMigrationPreviewService::class);

        $this->lastPreview = [
            'members' => $previewService->previewMembers($paths['members']),
            'loans' => $previewService->previewLoans($paths['loans']),
            'payments' => $this->isHistoricalStrategy()
                ? $previewService->previewPayments($paths['payments'])
                : null,
        ];

        $this->currentStep = 3;

        Notification::make()
            ->title(__('Preview ready'))
            ->success()
            ->send();
    }

    public function classifyPayments(): void
    {
        try {
            $state = $this->workflowState();
        } catch (ValidationException $exception) {
            $this->notifyFormValidationFailed($exception);

            return;
        }

        if (!$this->isHistoricalStrategy()) {
            Notification::make()
                ->title(__('Historical strategy required'))
                ->body(__('Switch migration strategy to Historical on the Strategy step to classify payments.'))
                ->warning()
                ->send();

            return;
        }

        $this->persistUploadSettingsFromState($state);
        $this->tryCacheUploadPaths();

        $paths = $this->resolveWorkflowPaths($state);

        if ($paths['payments'] === null) {
            Notification::make()
                ->title(__('Payments CSV required'))
                ->body(__('Upload a payments CSV on the Upload step and wait until it finishes uploading.'))
                ->warning()
                ->send();

            return;
        }

        if ($paths['members'] === null && Member::query()->doesntExist()) {
            Notification::make()
                ->title(__('Members CSV required'))
                ->body(__('Upload a members CSV so payment rows can be matched by member number or name.'))
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
            Setting::set('legacy_migration', 'classify_status', 'running');
            Setting::set('legacy_migration', 'classify_error', '');
            $this->classificationRunning = true;
            $this->lastKnownClassificationStatus = 'running';

            $job = new ClassifyLegacyPaymentsJob(
                paymentsPath: $paths['payments'],
                cutoffDate: filled($state['cutoff_date'] ?? null) ? (string) $state['cutoff_date'] : null,
                membersPath: $paths['members'],
                loansPath: $paths['loans'],
                notifyUserId: auth('tenant')->id(),
            );

            if (app()->environment('testing')) {
                dispatch_sync($job);
                $this->refreshClassificationStateFromSettings();

                Notification::make()
                    ->title($this->classifiedPaymentsReady ? __('Payments classified') : __('Classification finished with errors'))
                    ->body($this->classificationSummaryNotificationBody())
                    ->color($this->classifiedPaymentsReady ? 'success' : 'warning')
                    ->persistent(!$this->classifiedPaymentsReady)
                    ->send();
            } else {
                ClassifyLegacyPaymentsJob::dispatch(
                    $paths['payments'],
                    filled($state['cutoff_date'] ?? null) ? (string) $state['cutoff_date'] : null,
                    $paths['members'],
                    $paths['loans'],
                    auth('tenant')->id(),
                );

                Notification::make()
                    ->title(__('Classifying payments'))
                    ->body(__('Classification is running in the background. Results will appear on this step when finished.'))
                    ->success()
                    ->send();
            }

            $this->currentStep = 3;
        } catch (\Throwable $e) {
            report($e);

            Setting::set('legacy_migration', 'classify_status', 'failed');
            Setting::set('legacy_migration', 'classify_error', $e->getMessage());
            $this->classificationRunning = false;

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
            ['Content-Type' => 'text/csv'],
        );
    }

    public function runMigration(bool $dryRun = false): void
    {
        try {
            $state = $this->workflowState(requirePassword: !$dryRun);
        } catch (ValidationException $exception) {
            $this->notifyFormValidationFailed($exception);

            return;
        }

        $this->persistUploadSettingsFromState($state);

        $paths = $this->resolveWorkflowPaths($state);
        $password = (string) ($state['default_password'] ?? '');

        if ($paths['members'] === null) {
            Notification::make()
                ->title(__('Members CSV required'))
                ->body(__('Upload a members CSV and wait until it finishes uploading before running the migration.'))
                ->danger()
                ->send();

            return;
        }

        if (strlen($password) < 8 && !$dryRun) {
            Notification::make()
                ->title(__('Default password required'))
                ->body(__('Enter a default password of at least 8 characters on the Upload step.'))
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
            'grace_cycles' => (int) ($state['grace_cycles'] ?? LegacyMigrationGraceCycleSettings::defaultGraceCycles()),
            'loan_funding_strategy' => (string) ($state['loan_funding_strategy'] ?? LegacyMigrationFundingStrategySettings::defaultFundingStrategy()),
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

                $this->currentStep = 4;
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

            $this->currentStep = 4;
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

    /**
     * @return array<string, mixed>
     */
    private function workflowState(bool $requirePassword = false, bool $requireMembersUpload = false): array
    {
        $rules = [
            'data.strategy' => ['required', 'in:snapshot,historical'],
            'data.cutoff_date' => ['required', 'date'],
            'data.slash_date_format' => ['required', 'string'],
        ];

        if ($requirePassword) {
            $rules['data.default_password'] = ['required', 'string', 'min:8'];
        }

        $this->validate($rules);

        $state = $this->data;

        if ($requireMembersUpload) {
            $paths = $this->resolveWorkflowPaths($state);

            if ($paths['members'] === null) {
                throw ValidationException::withMessages([
                    'data.members_csv' => __('Upload a members CSV and wait until it finishes uploading.'),
                ]);
            }
        }

        return $state;
    }

    private function refreshClassificationStateFromSettings(): void
    {
        $status = (string) Setting::get('legacy_migration', 'classify_status', 'idle');
        $this->classificationRunning = $status === 'running';
        $this->classifiedPaymentsReady = Storage::disk('local')->exists(self::CLASSIFIED_PAYMENTS_PATH);

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
                $this->classificationErrors = $decoded;
            }
        }
    }

    private function classificationSummaryNotificationBody(): string
    {
        $stats = $this->classificationStats ?? [];

        $body = __('Contributions: :c · Loan repayments: :l · Unclassified: :u · Ignored: :i · Failed: :f', [
            'c' => $stats['contribution'] ?? 0,
            'l' => $stats['loan_repayment'] ?? 0,
            'u' => $stats['unclassified'] ?? 0,
            'i' => $stats['ignore'] ?? 0,
            'f' => $stats['failed'] ?? 0,
        ]);

        if ($this->classificationErrors !== []) {
            $body .= "\n\n" . implode("\n", array_slice($this->classificationErrors, 0, 5));
        }

        if ($this->classifiedPaymentsReady) {
            $body .= "\n\n" . __('Download the classified CSV below, review payment_type, then continue to Import.');
        }

        return $body;
    }

    private function tryCacheUploadPaths(): void
    {
        $paths = $this->resolveUploadedPathsFromState($this->data);

        if ($paths['members'] === null && $paths['loans'] === null && $paths['payments'] === null) {
            return;
        }

        $this->cachedUploadPaths = [
            'members' => $paths['members'],
            'loans' => $paths['loans'],
            'payments' => $paths['payments'],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{members: ?string, loans: ?string, payments: ?string, relatives: list<string>}
     */
    private function resolveWorkflowPaths(array $state): array
    {
        $paths = $this->resolveUploadedPathsFromState($state);

        if ($this->cachedUploadPaths === null) {
            return $paths;
        }

        return [
            'members' => $paths['members'] ?? $this->cachedUploadPaths['members'],
            'loans' => $paths['loans'] ?? $this->cachedUploadPaths['loans'],
            'payments' => $paths['payments'] ?? $this->cachedUploadPaths['payments'],
            'relatives' => $paths['relatives'],
        ];
    }

    private function refreshMigrationStateFromSettings(): void
    {
        $status = (string) Setting::get('legacy_migration', 'run_status', 'idle');
        $this->migrationRunning = $status === 'running';
        $this->migrationLastError = (string) Setting::get('legacy_migration', 'last_error', '');

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
     * @param  array<string, mixed>  $state
     */
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
