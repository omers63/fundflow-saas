<?php

namespace App\Filament\Tenant\Resources\Members\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberArrearsInventory;
use App\Filament\Support\MemberDelinquencyActions;
use App\Filament\Support\MemberFilamentActions;
use App\Filament\Support\MemberListTableHeaderActions;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MembersTable
{
    public static function configure(Table $table): Table
    {
        $currency = fn (): string => Setting::get('general', 'currency', 'USD');

        $table = $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'loans as active_loans_count' => fn (Builder $loanQuery): Builder => $loanQuery->where('status', 'active'),
            ]))
            ->headerActions(MemberListTableHeaderActions::all())
            ->columns([
                MemberTableColumns::number(label: __('Member #'))
                    ->searchable(),
                MemberTableColumns::name()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, Member $record): string => $record->adminStatusLabel())
                    ->color(fn (Member $record): string => $record->adminStatusBadgeColor()),
                TextColumn::make('cash_balance')
                    ->label(__('Cash'))
                    ->state(fn (Member $record): float => $record->getCashBalance())
                    ->money($currency)
                    ->color(fn (Member $record): string => $record->getCashBalance() < 0 ? 'danger' : 'success')
                    ->searchable(false)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByCashBalance($direction)),
                TextColumn::make('fund_balance')
                    ->label(__('Fund'))
                    ->state(fn (Member $record): float => $record->getFundBalance())
                    ->money($currency)
                    ->color(fn (Member $record): string => $record->getFundBalance() < 0 ? 'danger' : 'gray')
                    ->searchable(false)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByFundBalance($direction)),
                TextColumn::make('active_loans_count')
                    ->label(__('Active loans'))
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'info' : 'gray')
                    ->searchable(false)
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('monthly_contribution_amount')
                    ->money($currency)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('parent.name')
                    ->label(__('Parent'))
                    ->placeholder(__('Independent'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('joined_at')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Member::statusOptions()),
                SelectFilter::make('cycle_obligation')
                    ->label(__('Cycle obligation'))
                    ->options([
                        'contribution' => __('Contribution cycle'),
                        'loan_repayment' => __('Loan repayment'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'contribution' => $query->underContributionCycle(),
                            'loan_repayment' => $query->withActiveLoanRepaymentObligation(),
                            default => $query,
                        };
                    }),
                SelectFilter::make('parent_member_id')
                    ->label(__('Parent'))
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload(),
                DateColumnRangeFilter::make('joined_at', __('Joined')),
            ])
            ->toolbarActions([
                ...MemberFilamentActions::forMemberListBulkGroups(),
                ...MemberDelinquencyActions::forMemberListBulkGroups(),
                TableToolbar::refreshBulkAction(),
            ])
            ->defaultSort('name');

        $table = TableGrouping::apply($table, TableGrouping::members());

        // Always register arrears actions so row-click / mount resolve on the Arrears tab
        // even when table configure ran before the tab query param was applied.
        $viewArrears = self::viewArrearsAction();

        return $table
            ->recordUrl(function (Member $record): ?string {
                if (MemberResource::resolveListTab() === 'delinquent') {
                    return null;
                }

                return MemberResource::getUrl('view', ['record' => $record]);
            })
            ->recordAction(fn (): ?string => MemberResource::resolveListTab() === 'delinquent'
                ? 'viewArrears'
                : null)
            ->recordActions([
                ActionGroup::make([
                    $viewArrears,
                    Action::make('openProfile')
                        ->label(__('Open profile'))
                        ->icon('heroicon-o-user')
                        ->url(fn (Member $record): string => MemberResource::getUrl('view', ['record' => $record])),
                    ...MemberFilamentActions::forMemberListRow(),
                ])
                    ->label(__('Actions'))
                    ->icon('heroicon-o-ellipsis-vertical')
                    ->button()
                    ->visible(fn (): bool => MemberResource::resolveListTab() === 'delinquent'),
            ]);
    }

    private static function viewArrearsAction(): Action
    {
        return Action::make('viewArrears')
            ->label(__('View arrears'))
            ->icon('heroicon-o-banknotes')
            ->color('danger')
            ->slideOver()
            ->modalHeading(fn (Member $record): string => __('Arrears – :name', ['name' => $record->name]))
            ->modalDescription(__('Outstanding contribution and loan EMI arrears by labelled cycle.'))
            ->modalContent(fn (Member $record) => MemberArrearsInventory::modalContent($record))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }
}
