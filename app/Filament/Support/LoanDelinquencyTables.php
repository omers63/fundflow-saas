<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\ContributionArrearsClearanceService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanDelinquencyService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Component;

final class LoanDelinquencyTables
{
    public static function overdueInstallmentsQuery(): Builder
    {
        return LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereHas('loan', fn (Builder $q): Builder => $q->where('status', 'active'))
            ->with(['loan.member', 'loan.guarantor']);
    }

    public static function guarantorExposureQuery(): Builder
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

    public static function configureOverdueInstallmentsTable(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->query(self::overdueInstallmentsQuery())
            ->headerActions(LoanListTableHeaderActions::delinquency())
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
            ->filters([
                SelectFilter::make('member_id')
                    ->label(__('Member'))
                    ->options(fn (): array => Member::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'loan',
                            fn (Builder $loan): Builder => $loan->where('member_id', (int) $data['value']),
                        );
                    }),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                self::viewLoanInstallmentAction(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No overdue installments'))
            ->emptyStateDescription(__('Installments appear here after their cycle deadline passes and the delinquency check runs.')), TableGrouping::loanInstallments(includeLoanMember: true));
    }

    public static function configureGuarantorExposureTable(Table $table, ?Component $livewire = null): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        $transferAction = LoanFilamentActions::transferGuarantorLiability();
        $restoreAction = LoanFilamentActions::restoreBorrowerLiability();

        if ($livewire !== null) {
            $transferAction = $transferAction->after(fn (): mixed => LoanResource::dispatchInsightsRefresh($livewire));
            $restoreAction = $restoreAction->after(fn (): mixed => LoanResource::dispatchInsightsRefresh($livewire));
        }

        return TableGrouping::apply($table
            ->query(self::guarantorExposureQuery())
            ->headerActions(LoanListTableHeaderActions::delinquency())
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
                    })
                    ->searchable(false)
                    ->sortable(false),
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
                self::viewLoanAction(),
                $transferAction,
                $restoreAction,
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No guarantor exposure'))
            ->emptyStateDescription(__('Loans at warning stage or with liability transferred to the guarantor appear here.')), TableGrouping::delinquencyGuarantorLoans());
    }

    public static function configureContributionArrearsTable(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $delinquency = app(LoanDelinquencyService::class);
        [$throughMonth, $throughYear] = ContributionResource::resolveListCycle();
        $liveArrears = ContributionResource::isViewingOpenCycle();

        return TableGrouping::apply(
            $table
                ->headerActions(ContributionListTableHeaderActions::arrears())
                ->query(null)
                ->recordAction(null)
                ->recordUrl(null)
                ->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, ?array $filters) use ($delinquency, $throughMonth, $throughYear, $liveArrears): Collection {
                    $filters ??= [];

                    $memberId = isset($filters['member_id']['value'])
                        ? (int) $filters['member_id']['value']
                        : null;

                    if ($memberId === 0) {
                        $memberId = null;
                    }

                    $records = $delinquency->contributionArrearsTableRecords(
                        $memberId,
                        $throughMonth,
                        $throughYear,
                        $liveArrears,
                    );

                    return $delinquency->filterContributionArrearsRecords(
                        $records,
                        $search,
                        $sortColumn,
                        $sortDirection,
                        $memberId,
                    );
                })
                ->summaries(pageCondition: false, allTableCondition: false)
                ->columnManager(true)
                ->columns([
                    TextColumn::make('member_name')
                        ->label(__('Member'))
                        ->searchable(false)
                        ->sortable(false)
                        ->wrap()
                        ->url(fn (mixed $state, mixed $record): ?string => MemberTableColumns::resolveMemberUrl('member_name', $record)),
                    TextColumn::make('member_number')
                        ->label(__('Number'))
                        ->searchable(false)
                        ->sortable(false)
                        ->toggleable()
                        ->url(fn (mixed $state, mixed $record): ?string => MemberTableColumns::resolveMemberUrl('member_number', $record)),
                    TextColumn::make('period_label')
                        ->label(__('Period'))
                        ->searchable(false)
                        ->sortable(false),
                    TextColumn::make('year')
                        ->label(__('Year'))
                        ->sortable(false)
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('month')
                        ->label(__('Month'))
                        ->sortable(false)
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('contribution_status')
                        ->label(__('Contribution'))
                        ->badge()
                        ->sortable(false)
                        ->formatStateUsing(fn (?string $state): string => $delinquency->contributionStatusLabel((string) $state))
                        ->color(fn (?string $state): string => $delinquency->contributionStatusColor((string) $state)),
                    TextColumn::make('monthly_contribution_amount')
                        ->label(__('Monthly'))
                        ->sortable(false)
                        ->money($currency)
                        ->summarize([]),
                    TextColumn::make('late_fee')
                        ->label(__('Late fee'))
                        ->sortable(false)
                        ->money($currency)
                        ->summarize([]),
                    TextColumn::make('member_status')
                        ->label(__('Member status'))
                        ->badge()
                        ->sortable(false)
                        ->formatStateUsing(fn (?string $state): string => Member::statusOptions()[(string) $state] ?? (string) $state)
                        ->color(fn (?string $state): string => Member::statusBadgeColor((string) $state))
                        ->toggleable(isToggledHiddenByDefault: true),
                ])
                ->defaultSort('year', 'desc')
                ->filters([
                    SelectFilter::make('member_id')
                        ->label(__('Member'))
                        ->searchable()
                        ->getSearchResultsUsing(function (string $search): array {
                            $needle = trim($search);

                            if ($needle === '') {
                                return [];
                            }

                            return Member::query()
                                ->where('status', 'active')
                                ->where(function ($query) use ($needle): void {
                                    $query->where('name', 'like', "%{$needle}%")
                                        ->orWhere('member_number', 'like', "%{$needle}%");
                                })
                                ->orderBy('name')
                                ->limit(50)
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->getOptionLabelUsing(fn ($value): ?string => Member::query()->find($value)?->name),
                ])
                ->recordActions(TableRecordActionGroups::wrap([
                    self::applyContributionArrearsAction(),
                    self::clearContributionArrearsAction(),
                    self::viewMemberFromArrearsAction(),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        self::applyContributionArrearsBulkAction(),
                        self::clearContributionArrearsBulkAction(),
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->emptyStateHeading(__('No contribution arrears'))
                ->emptyStateDescription(__('Each row is one period after the deadline without a posted contribution (since the member joined).')),
            TableGrouping::delinquencyContributionArrears(),
        );
    }

    public static function viewLoanInstallmentAction(): Action
    {
        return Action::make('view_loan')
            ->label(__('View loan'))
            ->icon(Heroicon::OutlinedEye)
            ->url(fn (LoanInstallment $record): string => LoanResource::getUrl('view', ['record' => $record->loan_id]));
    }

    public static function viewLoanAction(): Action
    {
        return Action::make('view_loan')
            ->label(__('View loan'))
            ->icon(Heroicon::OutlinedEye)
            ->url(fn (Loan $record): string => LoanResource::getUrl('view', ['record' => $record]));
    }

    public static function viewMemberFromArrearsAction(): Action
    {
        return Action::make('view_member')
            ->label(__('View member'))
            ->icon(Heroicon::OutlinedEye)
            ->url(MemberTableColumns::memberIdEditUrl(...));
    }

    public static function applyContributionArrearsAction(): Action
    {
        return Action::make('apply_single')
            ->label(__('Apply now'))
            ->icon('heroicon-o-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(fn (array $record): string => __('Post contribution from cash for :period.', [
                'period' => $record['period_label'],
            ]))
            ->action(function (array $record, Action $action, Component $livewire): void {
                $outcome = self::applyContributionArrearsRecord($record);

                if ($outcome === 'skipped') {
                    return;
                }

                $member = Member::query()->find((int) $record['member_id']);
                ContributionTableActions::notifyApplyOutcome($outcome, $member?->name, $action);
                ContributionTableActions::refreshContributionViews($livewire);
            });
    }

    public static function applyContributionArrearsBulkAction(): BulkAction
    {
        return BulkAction::make('applySelected')
            ->label(__('Apply now'))
            ->icon('heroicon-o-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('Post contributions from member cash for each selected period.'))
            ->action(function (BulkAction $action, Collection $records, Component $livewire): void {
                $applied = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    $row = is_array($record) ? $record : (array) $record;
                    $outcome = self::applyContributionArrearsRecord($row);

                    if ($outcome === 'applied') {
                        $applied++;
                    } else {
                        $skipped++;
                    }
                }

                Notification::make()
                    ->title(__('Bulk apply complete'))
                    ->body(__(':applied applied, :skipped skipped or could not apply.', [
                        'applied' => $applied,
                        'skipped' => $skipped,
                    ]))
                    ->color($applied > 0 ? 'success' : 'warning')
                    ->send();

                ContributionTableActions::refreshContributionViews($livewire);

                if ($applied === 0 && $skipped > 0) {
                    ActionModalFailure::present(
                        $action,
                        __(':skipped period(s) could not be applied. Check cash balances and exemptions.', ['skipped' => $skipped]),
                        __('Bulk apply complete'),
                    );
                }
            });
    }

    public static function clearContributionArrearsAction(): Action
    {
        return Action::make('clear_arrears')
            ->label(__('Clear arrears'))
            ->icon('heroicon-o-x-circle')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Clear contribution arrears'))
            ->modalDescription(fn (array $record): string => __('Waive :period for :name without debiting cash. Pending late fees are reversed.', [
                'period' => $record['period_label'],
                'name' => $record['member_name'],
            ]))
            ->schema([
                Textarea::make('note')
                    ->label(__('Note (optional)'))
                    ->maxLength(500)
                    ->rows(2),
            ])
            ->action(function (array $record, array $data, Action $action, Component $livewire): void {
                $note = is_string($data['note'] ?? null) ? $data['note'] : null;

                try {
                    $outcome = app(ContributionArrearsClearanceService::class)->clearArrearsRecord($record, $note);
                } catch (\InvalidArgumentException $exception) {
                    ActionModalFailure::present($action, $exception->getMessage(), __('Could not clear arrears'));

                    return;
                }

                if ($outcome === 'already_clear') {
                    Notification::make()
                        ->title(__('Already clear'))
                        ->body(__('This period is already posted or waived.'))
                        ->info()
                        ->send();

                    return;
                }

                if ($outcome === 'skipped') {
                    Notification::make()
                        ->title(__('Could not clear arrears'))
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Arrears cleared'))
                    ->body(__(':period waived for :name.', [
                        'period' => $record['period_label'],
                        'name' => $record['member_name'],
                    ]))
                    ->success()
                    ->send();

                ContributionTableActions::refreshContributionViews($livewire);
            });
    }

    public static function clearContributionArrearsBulkAction(): BulkAction
    {
        return BulkAction::make('clearSelected')
            ->label(__('Clear arrears'))
            ->icon('heroicon-o-x-circle')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Clear contribution arrears'))
            ->modalDescription(__('Waive the selected periods without debiting member cash. Pending late fees are reversed.'))
            ->schema([
                Textarea::make('note')
                    ->label(__('Note (optional)'))
                    ->maxLength(500)
                    ->rows(2),
            ])
            ->action(function (BulkAction $action, Collection $records, array $data, Component $livewire): void {
                $note = is_string($data['note'] ?? null) ? $data['note'] : null;
                $summary = app(ContributionArrearsClearanceService::class)->clearManyRecords($records, $note);

                Notification::make()
                    ->title(__('Bulk clear complete'))
                    ->body(__(':cleared cleared · :already already clear · :skipped skipped', [
                        'cleared' => $summary['cleared'],
                        'already' => $summary['already_clear'],
                        'skipped' => $summary['skipped'],
                    ]))
                    ->color($summary['cleared'] > 0 ? 'success' : 'warning')
                    ->send();

                ContributionTableActions::refreshContributionViews($livewire);

                if ($summary['cleared'] === 0 && ($summary['skipped'] > 0 || $summary['already_clear'] > 0)) {
                    ActionModalFailure::present(
                        $action,
                        __('No periods were waived. Rows may already be clear or the member is exempt for that cycle.'),
                        __('Bulk clear complete'),
                    );
                }
            });
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function applyContributionArrearsRecord(array $record): string
    {
        $member = Member::query()->find((int) ($record['member_id'] ?? 0));

        if ($member === null) {
            return 'skipped';
        }

        return app(ContributionCycleService::class)->applyContributionForMemberForPeriod(
            $member,
            (int) $record['month'],
            (int) $record['year'],
        );
    }
}
