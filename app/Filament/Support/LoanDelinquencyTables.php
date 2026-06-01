<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanDelinquencyService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
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

        return $table
            ->query(null)
            ->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, ?array $filters) use ($delinquency): Collection {
                $filters ??= [];

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
            ->summaries(pageCondition: false, allTableCondition: false)
            ->columnManager(false)
            ->columns([
                TextColumn::make('member_name')
                    ->label(__('Member'))
                    ->searchable(false)
                    ->sortable(false)
                    ->wrap()
                    ->url(MemberTableColumns::memberIdEditUrl(...)),
                TextColumn::make('member_number')
                    ->label(__('Number'))
                    ->searchable(false)
                    ->sortable(false)
                    ->url(MemberTableColumns::memberIdEditUrl(...)),
                TextColumn::make('period_label')
                    ->label(__('Period'))
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('year')
                    ->label(__('Year'))
                    ->sortable(false)
                    ->toggleable(false),
                TextColumn::make('month')
                    ->label(__('Month'))
                    ->sortable(false)
                    ->toggleable(false),
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
                    ->toggleable(false),
            ])
            ->defaultSort('year', 'desc')
            ->headerActions([
                ContributionListTableHeaderActions::delinquencyToolsGroup(),
            ])
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
                self::viewMemberFromArrearsAction(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No contribution arrears'))
            ->emptyStateDescription(__('Each row is one period after the deadline without a posted contribution (since the member joined).'));
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
}
