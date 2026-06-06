<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Services\LegacyMigration\LegacyMigrationOrchestrator;
use App\Services\LegacyMigration\LegacyMigrationPreviewService;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use App\Support\BusinessDay;
use App\Support\FilamentStoredUploadPath;
use BackedEnum;
use Carbon\Carbon;
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
use UnitEnum;

class LegacyMigrationPage extends Page implements HasForms
{
    use InteractsWithForms;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?string $navigationLabel = 'Legacy migration';

    protected static ?string $slug = 'legacy-migration';

    protected static ?int $navigationSort = TenantNavigation::SORT_MIGRATIONS;

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    protected string $view = 'filament.tenant.pages.legacy-migration';

    public int $currentStep = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $lastPreview = null;

    /** @var array<string, mixed>|null */
    public ?array $lastRun = null;

    /** @var array<string, mixed>|null */
    public ?array $classificationStats = null;

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public function mount(): void
    {
        $this->form->fill([
            'strategy' => 'snapshot',
            'cutoff_date' => BusinessDay::now()->subMonth()->endOfMonth()->toDateString(),
            'default_password' => '',
        ]);
    }

    public function getTitle(): string
    {
        return __('Legacy migration');
    }

    public function getSubheading(): ?string
    {
        return __('Import members, loans, and optional payment history from your previous system using CSV templates.');
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
                    ->required()
                    ->minLength(8)
                    ->helperText(__('Used for imported members when the CSV password column is empty.')),
                FileUpload::make('members_csv')
                    ->label(__('Members CSV'))
                    ->disk('local')
                    ->directory('legacy-migration')
                    ->maxFiles(1)
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->helperText(__('Required. Include cutoff_cash_balance and cutoff_fund_balance per member when known.')),
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
                    ->helperText(__('Optional for historical strategy. Classify rows before import, or set payment_type explicitly.')),
            ]);
    }

    public function goToStep(int $step): void
    {
        $this->currentStep = max(1, min(5, $step));
    }

    public function previewMigration(): void
    {
        $paths = $this->resolveUploadedPaths();

        if ($paths['members'] === null) {
            Notification::make()
                ->title(__('Members CSV required'))
                ->body(__('Upload a members CSV before previewing.'))
                ->warning()
                ->send();

            return;
        }

        $previewService = app(LegacyMigrationPreviewService::class);

        $this->lastPreview = [
            'members' => $previewService->previewMembers($paths['members']),
            'loans' => $previewService->previewLoans($paths['loans']),
            'payments' => ($this->data['strategy'] ?? 'snapshot') === 'historical'
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
        $paths = $this->resolveUploadedPaths();

        if ($paths['payments'] === null) {
            Notification::make()
                ->title(__('Payments CSV required'))
                ->warning()
                ->send();

            return;
        }

        try {
            $cutoff = filled($this->data['cutoff_date'] ?? null)
                ? Carbon::parse((string) $this->data['cutoff_date'])
                : null;

            $result = app(LegacyPaymentClassifierService::class)->classifyFile($paths['payments'], $cutoff);
            $this->classificationStats = $result['stats'];

            Notification::make()
                ->title(__('Payments classified'))
                ->body(__('Contributions: :c · Loan repayments: :l · Unclassified: :u · Ignored: :i', [
                    'c' => $result['stats']['contribution'],
                    'l' => $result['stats']['loan_repayment'],
                    'u' => $result['stats']['unclassified'],
                    'i' => $result['stats']['ignore'],
                ]))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Classification failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function runMigration(bool $dryRun = false): void
    {
        $paths = $this->resolveUploadedPaths();
        $password = (string) ($this->data['default_password'] ?? '');

        if ($paths['members'] === null) {
            Notification::make()
                ->title(__('Members CSV required'))
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

        try {
            $this->lastRun = app(LegacyMigrationOrchestrator::class)->run([
                'cutoff_date' => $this->data['cutoff_date'] ?? null,
                'default_password' => $password,
                'members_path' => $paths['members'],
                'loans_path' => $paths['loans'],
                'payments_path' => $paths['payments'],
                'strategy' => $this->data['strategy'] ?? 'snapshot',
            ], $dryRun);

            $members = $this->lastRun['members'];

            Notification::make()
                ->title($dryRun ? __('Dry run complete') : __('Migration complete'))
                ->body($dryRun
                    ? __('Would import :count member row(s). Review the summary below.', ['count' => $members['created']])
                    : __('Created: :created · Skipped: :skipped · Failed: :failed', [
                        'created' => $members['created'],
                        'skipped' => $members['skipped'],
                        'failed' => $members['failed'],
                    ]))
                ->success()
                ->send();

            $this->currentStep = 5;
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title($dryRun ? __('Dry run failed') : __('Migration failed'))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        } finally {
            $this->cleanupUploadedPaths($paths);
        }
    }

    /**
     * @return array{members: ?string, loans: ?string, payments: ?string, relatives: list<string>}
     */
    private function resolveUploadedPaths(): array
    {
        $relatives = [];
        $members = $this->resolveCsvPath($this->data['members_csv'] ?? null, $relatives);
        $loans = $this->resolveCsvPath($this->data['loans_csv'] ?? null, $relatives);
        $payments = $this->resolveCsvPath($this->data['payments_csv'] ?? null, $relatives);

        return [
            'members' => $members,
            'loans' => $loans,
            'payments' => $payments,
            'relatives' => $relatives,
        ];
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
}
