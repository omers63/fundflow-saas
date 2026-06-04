<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\FiscalClose;
use App\Models\Tenant\User;
use App\Services\FiscalClose\FiscalClosePeriodResolver;
use App\Services\FiscalClose\FiscalCloseReadinessService;
use App\Services\FiscalClose\FiscalCloseService;
use App\Services\FiscalClose\FiscalYearPeriod;
use App\Support\FiscalSettings;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class FiscalYearClosePage extends Page implements HasForms
{
    use InteractsWithForms;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_ACCOUNTS;

    protected static ?string $navigationLabel = 'Year-end close';

    protected static ?string $slug = 'fiscal-year-close';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Year-end close';

    protected string $view = 'filament.tenant.pages.fiscal-year-close';

    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $readinessReport = null;

    /** @var array<string, mixed>|null */
    public ?array $activeClose = null;

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public function mount(): void
    {
        $period = app(FiscalClosePeriodResolver::class)->resolvePeriodContaining();

        $this->form->fill([
            'fiscal_year_label' => $period->label,
            'period_end' => $period->periodEnd->toDateString(),
        ]);

        $this->refreshActiveClose();
    }

    public function form(Schema $schema): Schema
    {
        $closedThrough = FiscalSettings::booksClosedThrough();

        return $schema
            ->statePath('data')
            ->schema([
                Section::make(__('Close workflow'))
                    ->description(__('Validate, snapshot, roll forward opening balances, and optionally purge Tier A ledger detail.'))
                    ->columns(2)
                    ->schema([
                        Hidden::make('fiscal_year_label')
                            ->dehydrated(),
                        Placeholder::make('fiscal_year_label_display')
                            ->label(__('Fiscal year'))
                            ->content(fn(): string => (string) ($this->data['fiscal_year_label'] ?? '—')),
                        DatePicker::make('period_end')
                            ->label(__('Proposed period end'))
                            ->native(false)
                            ->required()
                            ->disabled(fn(): bool => $this->closeIsLocked())
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                if (!filled($state)) {
                                    return;
                                }

                                $period = app(FiscalClosePeriodResolver::class)->resolvePeriodContaining(
                                    Carbon::parse($state)->startOfDay(),
                                );
                                $set('fiscal_year_label', $period->label);
                                $this->refreshActiveClose();
                            }),
                        Placeholder::make('books_closed_through')
                            ->label(__('Books closed through'))
                            ->columnSpanFull()
                            ->content($closedThrough !== null
                                ? $closedThrough->toFormattedDateString()
                                : __('Not set — books are open')),
                        Placeholder::make('active_close_status')
                            ->label(__('Close record'))
                            ->columnSpanFull()
                            ->content(fn(): string => $this->activeCloseSummary()),
                    ]),
            ]);
    }

    public function getTitle(): string
    {
        return __('Year-end close');
    }

    public function getSubheading(): ?string
    {
        return __('Validate, snapshot, archive exports, roll forward, and purge closed-period ledger detail (Tiers A and B).');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_settings')
                ->label(__('Fiscal calendar settings'))
                ->icon('heroicon-o-cog-6-tooth')
                ->url(Settings::getUrl()),
            Action::make('run_readiness')
                ->label(__('Run readiness checks'))
                ->icon('heroicon-o-shield-check')
                ->color('gray')
                ->disabled(fn(): bool => $this->closeIsLocked())
                ->action(function (): void {
                    $state = $this->form->getState();
                    $periodEnd = $this->periodEndFromState($state);
                    $fiscalYearLabel = $this->fiscalYearLabelFromState($state);

                    $report = app(FiscalCloseReadinessService::class)->assess(
                        $periodEnd,
                        $fiscalYearLabel,
                    );

                    $this->readinessReport = $report->toArray();

                    Notification::make()
                        ->title($report->canProceed()
                            ? __('Ready for snapshot')
                            : __('Close blocked — resolve failing checks'))
                        ->body($report->canProceed()
                            ? __('You can build a certified snapshot when all required gates pass.')
                            : __(':count gate(s) failed.', ['count' => count($report->failingGates())]))
                        ->color($report->canProceed() ? 'success' : 'danger')
                        ->send();
                }),
            Action::make('build_snapshot')
                ->label(__('Build snapshot'))
                ->icon('heroicon-o-camera')
                ->color('primary')
                ->visible(fn(): bool => ($this->readinessReport['can_proceed'] ?? false) === true && !$this->closeIsLocked())
                ->requiresConfirmation()
                ->modalHeading(__('Build certified snapshot'))
                ->modalDescription(__('Captures pool totals and per-member balances, arrears, and loan state. No ledger rows are changed yet.'))
                ->action(function (): void {
                    $state = $this->form->getState();
                    $operator = $this->tenantOperator();

                    try {
                        $close = app(FiscalCloseService::class)->prepareSnapshot(
                            $this->fiscalYearLabelFromState($state),
                            $this->periodEndFromState($state),
                            $operator,
                        );
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()->title(__('Cannot build snapshot'))->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    $this->activeClose = $close->toSummaryArray();
                    $this->readinessReport = $close->readiness_report_json;

                    Notification::make()
                        ->title(__('Snapshot built'))
                        ->body(__('Checksum :checksum · :count members captured.', [
                            'checksum' => substr((string) $close->checksum, 0, 12) . '…',
                            'count' => $close->member_count,
                        ]))
                        ->success()
                        ->send();
                }),
            Action::make('generate_exports')
                ->label(__('Generate archive'))
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('gray')
                ->visible(fn(): bool => $this->canGenerateExports())
                ->requiresConfirmation()
                ->modalHeading(__('Generate archive exports'))
                ->modalDescription(__('Writes GL, arrears, loan portfolio, and readiness report files to tenant storage. Required before purge when retention policy is archive-then-delete.'))
                ->action(function (): void {
                    $close = FiscalClose::query()->find($this->activeClose['id'] ?? 0);

                    if ($close === null) {
                        Notification::make()->title(__('Close record not found'))->danger()->send();

                        return;
                    }

                    try {
                        $manifest = app(FiscalCloseService::class)->generateExports($close);
                        $close = $close->fresh();
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()->title(__('Export failed'))->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    $this->activeClose = $close->toSummaryArray();

                    Notification::make()
                        ->title(__('Archive generated'))
                        ->body(__(':count export file(s) ready for download.', [
                            'count' => count($manifest['files'] ?? []),
                        ]))
                        ->success()
                        ->send();
                }),
            Action::make('execute_roll_forward')
                ->label(__('Execute roll-forward'))
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->visible(fn(): bool => $this->canExecuteRollForward())
                ->requiresConfirmation()
                ->modalHeading(__('Execute roll-forward'))
                ->modalDescription(__('Updates member opening balances, sets books closed through :date, and locks backdated postings. Ledger history is retained until purge.', [
                    'date' => (string) ($this->activeClose['period_end'] ?? $this->data['period_end'] ?? ''),
                ]))
                ->action(function (): void {
                    $close = FiscalClose::query()->find($this->activeClose['id'] ?? 0);

                    if ($close === null) {
                        Notification::make()->title(__('Close record not found'))->danger()->send();

                        return;
                    }

                    try {
                        $close = app(FiscalCloseService::class)->approveAndRollForward(
                            $close,
                            $this->tenantOperator(),
                        );
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()->title(__('Roll-forward failed'))->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    $this->activeClose = $close->toSummaryArray();

                    Notification::make()
                        ->title(__('Roll-forward complete'))
                        ->body(__('Books closed through :date. Backdated postings are now blocked.', [
                            'date' => $close->period_end->toFormattedDateString(),
                        ]))
                        ->success()
                        ->send();
                }),
            Action::make('execute_tier_a_purge')
                ->label(__('Purge Tier A ledger'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn(): bool => $this->canExecuteTierAPurge())
                ->requiresConfirmation()
                ->modalHeading(__('Purge Tier A ledger detail'))
                ->modalDescription(__('Permanently deletes transactions through the close period, cleared bank lines, and resolved reconciliation exceptions. Account balances and open arrears are not changed. This cannot be undone.'))
                ->action(function (): void {
                    $close = FiscalClose::query()->find($this->activeClose['id'] ?? 0);

                    if ($close === null) {
                        Notification::make()->title(__('Close record not found'))->danger()->send();

                        return;
                    }

                    try {
                        $summary = app(FiscalCloseService::class)->executeTierAPurge($close);
                        $close = $close->fresh();
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()->title(__('Purge failed'))->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    $this->activeClose = $close->toSummaryArray();

                    Notification::make()
                        ->title(FiscalSettings::includesTierBPurge()
                            ? __('Tier A purge complete')
                            : __('Purge complete'))
                        ->body(__('Deleted :tx transactions, :bank bank lines, :recon resolved exceptions.', [
                            'tx' => $summary['transactions'] ?? 0,
                            'bank' => $summary['bank_transactions'] ?? 0,
                            'recon' => $summary['reconciliation_exceptions'] ?? 0,
                        ]))
                        ->success()
                        ->send();
                }),
            Action::make('execute_tier_b_purge')
                ->label(__('Purge Tier B records'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn(): bool => $this->canExecuteTierBPurge())
                ->requiresConfirmation()
                ->modalHeading(__('Purge Tier B operational history'))
                ->modalDescription(__('Permanently deletes collected contributions, paid installments, closed fund postings, and audit log rows through the close period. Open arrears and pending installments are kept. This cannot be undone.'))
                ->action(function (): void {
                    $close = FiscalClose::query()->find($this->activeClose['id'] ?? 0);

                    if ($close === null) {
                        Notification::make()->title(__('Close record not found'))->danger()->send();

                        return;
                    }

                    try {
                        $summary = app(FiscalCloseService::class)->executeTierBPurge($close);
                        $close = $close->fresh();
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()->title(__('Tier B purge failed'))->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    $this->activeClose = $close->toSummaryArray();

                    Notification::make()
                        ->title(__('Tier B purge complete'))
                        ->body(__('Deleted :contributions contributions, :installments installments, :postings fund postings, :audit audit rows.', [
                            'contributions' => $summary['contributions'] ?? 0,
                            'installments' => $summary['loan_installments'] ?? 0,
                            'postings' => $summary['fund_postings'] ?? 0,
                            'audit' => $summary['fund_audit_log'] ?? 0,
                        ]))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function exportDownloadLinks(): array
    {
        $closeId = $this->activeClose['id'] ?? null;
        $files = $this->activeClose['export_manifest_json']['files'] ?? [];

        if ($closeId === null || !is_array($files)) {
            return [];
        }

        $labels = [
            'gl' => __('General ledger'),
            'arrears_aging' => __('Arrears aging'),
            'loan_portfolio' => __('Loan portfolio'),
            'readiness_report' => __('Readiness report'),
        ];

        $links = [];

        foreach ($files as $key => $path) {
            if (!is_string($path) || blank($path)) {
                continue;
            }

            $links[$key] = [
                'label' => $labels[$key] ?? (string) $key,
                'url' => route('tenant.admin.fiscal-close.export', [
                    'fiscalClose' => $closeId,
                    'fileKey' => $key,
                ]),
            ];
        }

        return $links;
    }

    public function currentPeriod(): FiscalYearPeriod
    {
        return app(FiscalClosePeriodResolver::class)->resolvePeriodContaining();
    }

    protected function refreshActiveClose(): void
    {
        $label = $this->fiscalYearLabelFromState($this->data ?? []);

        if ($label === '') {
            $this->activeClose = null;

            return;
        }

        $close = app(FiscalCloseService::class)->latestForLabel($label);
        $this->activeClose = $close?->toSummaryArray();

        if ($close?->readiness_report_json !== null) {
            $this->readinessReport = $close->readiness_report_json;
        }
    }

    protected function closeIsLocked(): bool
    {
        return in_array($this->activeClose['status'] ?? null, [
            FiscalClose::STATUS_ROLLED_FORWARD,
            FiscalClose::STATUS_PURGED,
        ], true);
    }

    protected function canGenerateExports(): bool
    {
        if ($this->activeClose === null) {
            return false;
        }

        return in_array($this->activeClose['status'] ?? '', [
            FiscalClose::STATUS_SNAPSHOT,
            FiscalClose::STATUS_PENDING_APPROVAL,
            FiscalClose::STATUS_ROLLED_FORWARD,
        ], true) && empty($this->activeClose['export_manifest_json']['files'] ?? []);
    }

    protected function canExecuteTierAPurge(): bool
    {
        $close = FiscalClose::query()->find($this->activeClose['id'] ?? 0);

        return $close instanceof FiscalClose && $close->canPurgeTierA();
    }

    protected function canExecuteTierBPurge(): bool
    {
        $close = FiscalClose::query()->find($this->activeClose['id'] ?? 0);

        return $close instanceof FiscalClose && $close->canPurgeTierB();
    }

    protected function canExecuteRollForward(): bool
    {
        return in_array($this->activeClose['status'] ?? '', [
            FiscalClose::STATUS_SNAPSHOT,
            FiscalClose::STATUS_PENDING_APPROVAL,
        ], true);
    }

    protected function activeCloseSummary(): string
    {
        if ($this->activeClose === null) {
            return __('No close record for this fiscal year yet.');
        }

        return __(':label · :status · :count members · checksum :checksum', [
            'label' => $this->activeClose['fiscal_year_label'] ?? '—',
            'status' => $this->activeClose['status'] ?? '—',
            'count' => $this->activeClose['member_count'] ?? 0,
            'checksum' => filled($this->activeClose['checksum'] ?? null)
                ? substr((string) $this->activeClose['checksum'], 0, 12) . '…'
                : '—',
        ]);
    }

    protected function tenantOperator(): User
    {
        $user = auth()->guard('tenant')->user();

        if (!$user instanceof User) {
            throw new \RuntimeException(__('Tenant admin user required.'));
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function fiscalYearLabelFromState(array $state): string
    {
        if (filled($state['fiscal_year_label'] ?? null)) {
            return (string) $state['fiscal_year_label'];
        }

        if (filled($state['period_end'] ?? null)) {
            return app(FiscalClosePeriodResolver::class)
                ->resolvePeriodContaining(Carbon::parse((string) $state['period_end'])->startOfDay())
                ->label;
        }

        return $this->currentPeriod()->label;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function periodEndFromState(array $state): Carbon
    {
        $raw = $state['period_end'] ?? $this->data['period_end'] ?? null;

        if (filled($raw)) {
            return Carbon::parse((string) $raw)->startOfDay();
        }

        return $this->currentPeriod()->periodEnd->copy()->startOfDay();
    }
}
