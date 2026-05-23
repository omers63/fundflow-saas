<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Support\LoanFilamentActions;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Widgets\LoanInsightsWidget;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\ContributionCycleService;
use App\Services\Loans\DelinquencyDigestService;
use App\Services\Loans\LoanDelinquencyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Component;

class ListDelinquency extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = LoanResource::class;

    protected static ?string $navigationLabel = 'Delinquency';

    protected static ?string $title = 'Delinquency';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'delinquency';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.tenant.resources.loans.pages.list-delinquency';

    public string $delinquencyTab = 'installments';

    public function getTitle(): string
    {
        return __('Delinquency');
    }

    public function mount(): void
    {
        $tab = request()->query('tab');
        if (in_array($tab, ['installments', 'contributions', 'guarantor'], true)) {
            $this->delinquencyTab = $tab;
        }
    }

    public function setDelinquencyTab(string $tab): void
    {
        if (! in_array($tab, ['installments', 'contributions', 'guarantor'], true)) {
            return;
        }

        $this->delinquencyTab = $tab;
        $this->resetTable();
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        return 'delinquency_'.$this->delinquencyTab;
    }

    public function getSubheading(): ?string
    {
        $cycles = app(ContributionCycleService::class);
        [$m, $y] = $cycles->currentOpenPeriod();

        return __('Track late loan installments, contribution arrears, and guarantor liability. Open period: :period.', [
            'period' => $cycles->periodLabel($m, $y),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runMaintenance')
                ->label(__('Run delinquency check'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(__('Run delinquency check'))
                ->modalDescription(__('Marks overdue installments, updates member delinquency status, and processes default warnings or guarantor debits per fund rules.'))
                ->action(function (LoanDelinquencyService $delinquency): void {
                    $result = $delinquency->runDailyMaintenance();

                    Notification::make()
                        ->title(__('Delinquency check complete'))
                        ->body(__('Overdue: :overdue · Delinquent: :delinquent · Restored: :restored · Warnings: :warned · Guarantor debits: :debited', [
                            'overdue' => $result['marked_overdue'],
                            'delinquent' => $result['marked_delinquent'],
                            'restored' => $result['restored_active'],
                            'warned' => $result['warned'],
                            'debited' => $result['debited_from_guarantor'],
                        ]))
                        ->success()
                        ->send();

                    LoanResource::dispatchInsightsRefresh($this);
                    $this->resetTable();
                }),
            Action::make('markOverdueOnly')
                ->label(__('Mark overdue only'))
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->requiresConfirmation()
                ->action(function (LoanDelinquencyService $delinquency): void {
                    $count = $delinquency->markOverdueInstallments();

                    Notification::make()
                        ->title(__('Installments updated'))
                        ->body(__(':count installment(s) marked overdue.', ['count' => $count]))
                        ->success()
                        ->send();

                    LoanResource::dispatchInsightsRefresh($this);
                    $this->resetTable();
                }),
            Action::make('sendDigest')
                ->label(__('Send admin digest'))
                ->icon('heroicon-o-bell-alert')
                ->color('info')
                ->requiresConfirmation()
                ->modalDescription(__('Notifies tenant administrators when overdue installments, contribution arrears, or guarantor exposure need attention.'))
                ->action(function (DelinquencyDigestService $digest): void {
                    $count = $digest->notifyAdminsIfNeeded();

                    Notification::make()
                        ->title($count > 0 ? __('Digest sent') : __('Nothing to report'))
                        ->body($count > 0
                            ? __(':count administrator(s) notified.', ['count' => $count])
                            : __('No overdue installments, contribution arrears, or guarantor exposure.'))
                        ->color($count > 0 ? 'success' : 'info')
                        ->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            LoanInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            'context' => 'delinquency',
        ];
    }

    public function table(Table $table): Table
    {
        return match ($this->delinquencyTab) {
            'contributions' => $this->contributionsTable($table),
            'guarantor' => $this->guarantorTable($table),
            default => $this->installmentsTable($table),
        };
    }

    protected function installmentsTable(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->query($this->getTableQuery())
            ->columnManager(true)
            ->columns([
                TextColumn::make('loan.member.name')
                    ->label(__('Member'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('loan_id')
                    ->label(__('Loan'))
                    ->formatStateUsing(fn (int $state): string => '#'.$state)
                    ->url(fn (LoanInstallment $record): string => LoanResource::getUrl('view', ['record' => $record->loan_id])),
                TextColumn::make('installment_number')
                    ->label(__('#'))
                    ->sortable(),
                TextColumn::make('due_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money($currency),
                TextColumn::make('late_fee_amount')
                    ->label(__('Late fee'))
                    ->money($currency)
                    ->placeholder(__('—')),
                TextColumn::make('loan.guarantor.name')
                    ->label(__('Guarantor'))
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('loan.guarantor_liability_transferred_at')
                    ->label(__('Liability'))
                    ->formatStateUsing(fn ($state): string => $state ? __('Guarantor') : __('Borrower'))
                    ->badge()
                    ->color(fn ($state): string => $state ? 'warning' : 'gray'),
            ])
            ->defaultSort('due_date')
            ->recordActions(TableRecordActionGroups::wrap([
                self::tableNavigationAction(
                    'view_loan',
                    __('View loan'),
                    fn (LoanInstallment $record): string => LoanResource::getUrl('view', ['record' => $record->loan_id]),
                ),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No overdue installments'))
            ->emptyStateDescription(__('Installments appear here after their cycle deadline passes and the delinquency check runs.')), TableGrouping::loanInstallments(includeLoanMember: true));
    }

    protected function contributionsTable(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $delinquency = app(LoanDelinquencyService::class);

        return TableGrouping::apply($table
            ->records(function (?string $search = null, ?string $sortColumn = null, ?string $sortDirection = null, ?array $filters = null) use ($delinquency): Collection {
                $memberId = isset($filters['member_id']['value'])
                    ? (int) $filters['member_id']['value']
                    : null;

                if ($memberId === 0) {
                    $memberId = null;
                }

                $records = $delinquency->contributionArrearsTableRecords($memberId);

                return $delinquency->filterContributionArrearsRecords(
                    $records,
                    $search,
                    $sortColumn,
                    $sortDirection,
                    $memberId,
                );
            })
            ->columnManager(true)
            ->columns([
                TextColumn::make('member_name')
                    ->label(__('Member'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->url(MemberTableColumns::memberIdEditUrl(...)),
                TextColumn::make('member_number')
                    ->label(__('Number'))
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->url(MemberTableColumns::memberIdEditUrl(...)),
                TextColumn::make('period_label')
                    ->label(__('Period'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('year')
                    ->label(__('Year'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('month')
                    ->label(__('Month'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('contribution_status')
                    ->label(__('Contribution'))
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => $delinquency->contributionStatusLabel($state))
                    ->color(fn (string $state): string => $delinquency->contributionStatusColor($state)),
                TextColumn::make('monthly_contribution_amount')
                    ->label(__('Monthly'))
                    ->sortable()
                    ->money($currency),
                TextColumn::make('late_fee')
                    ->label(__('Late fee'))
                    ->sortable()
                    ->money($currency),
                TextColumn::make('member_status')
                    ->label(__('Member status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Member::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => Member::statusBadgeColor($state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('year', 'desc')
            ->filters([
                SelectFilter::make('member_id')
                    ->label(__('Member'))
                    ->options(fn (): array => Member::query()
                        ->whereIn('id', $delinquency->contributionArrearsMemberIds() ?: [0])
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                self::tableNavigationAction(
                    'view_member',
                    __('View member'),
                    MemberTableColumns::memberIdEditUrl(...),
                ),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No contribution arrears'))
            ->emptyStateDescription(__('Each row is one period after the deadline without a posted contribution (since the member joined).')), TableGrouping::delinquencyContributionArrears());
    }

    protected function guarantorTable(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->query($this->getTableQuery())
            ->columnManager(true)
            ->columns([
                TextColumn::make('member.name')
                    ->label(__('Borrower'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('guarantor.name')
                    ->label(__('Guarantor'))
                    ->placeholder(__('—')),
                TextColumn::make('delinquency_stage')
                    ->label(__('Stage'))
                    ->state(function (Loan $record): string {
                        if ($record->guarantor_liability_transferred_at !== null) {
                            return __('Liability on guarantor');
                        }

                        $grace = Setting::loanDefaultGraceCycles();

                        return $record->late_repayment_count >= $grace
                            ? __('Ready for guarantor action')
                            : __('Warning cycle');
                    })
                    ->badge()
                    ->color(function (Loan $record): string {
                        if ($record->guarantor_liability_transferred_at !== null) {
                            return 'warning';
                        }

                        return Setting::loanDefaultGraceCycles() <= $record->late_repayment_count
                            ? 'danger'
                            : 'gray';
                    }),
                TextColumn::make('late_repayment_count')
                    ->label(__('Late count'))
                    ->numeric(),
                TextColumn::make('overdue_installments_count')
                    ->label(__('Overdue'))
                    ->numeric(),
                TextColumn::make('amount_disbursed')
                    ->label(__('Disbursed'))
                    ->money($currency),
                TextColumn::make('guarantor_liability_transferred_at')
                    ->label(__('Transferred'))
                    ->dateTime()
                    ->placeholder(__('—')),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                self::tableNavigationAction(
                    'view_loan',
                    __('View loan'),
                    fn (Loan $record): string => LoanResource::getUrl('view', ['record' => $record]),
                ),
                self::withInsightsRefresh(LoanFilamentActions::transferGuarantorLiability()),
                self::withInsightsRefresh(LoanFilamentActions::restoreBorrowerLiability()),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No guarantor exposure'))
            ->emptyStateDescription(__('Loans at warning stage or with liability transferred to the guarantor appear here.')), TableGrouping::delinquencyGuarantorLoans());
    }

    protected function getTableQuery(): Builder
    {
        if ($this->delinquencyTab === 'contributions') {
            return Member::query()->whereRaw('0 = 1');
        }

        return match ($this->delinquencyTab) {
            'guarantor' => $this->guarantorQuery(),
            default => $this->installmentsQuery(),
        };
    }

    protected function installmentsQuery(): Builder
    {
        return LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereHas('loan', fn (Builder $q): Builder => $q->where('status', 'active'))
            ->with(['loan.member', 'loan.guarantor']);
    }

    protected function guarantorQuery(): Builder
    {
        $grace = Setting::loanDefaultGraceCycles();

        return Loan::query()
            ->where('status', 'active')
            ->whereNotNull('guarantor_member_id')
            ->where(function (Builder $q) use ($grace): void {
                $q->whereNotNull('guarantor_liability_transferred_at')
                    ->orWhere(function (Builder $inner) use ($grace): void {
                        $inner->whereNull('guarantor_liability_transferred_at')
                            ->where('late_repayment_count', '>=', $grace)
                            ->whereHas('installments', fn (Builder $i): Builder => $i->where('status', 'overdue'));
                    });
            })
            ->with(['member', 'guarantor'])
            ->withCount([
                'installments as overdue_installments_count' => fn (Builder $q): Builder => $q->where('status', 'overdue'),
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereHas('loan', fn (Builder $q): Builder => $q->where('status', 'active'))
            ->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    private static function withInsightsRefresh(Action $action): Action
    {
        return $action->after(fn (Component $livewire): mixed => LoanResource::dispatchInsightsRefresh($livewire));
    }

    /**
     * Link-only row action (not {@see ViewAction}) so custom table records (arrays) do not hit
     * {@see LoanResource::getViewAuthorizationResponse()} with a non-model record.
     */
    private static function tableNavigationAction(string $name, string $label, callable $urlResolver): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedEye)
            ->url($urlResolver);
    }
}
