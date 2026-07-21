<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\BankAccounts\Tables;

use App\Filament\Support\BankClearingQueueActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableStandards;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Filament\Tenant\Support\ViewBankTransactionAction;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class PendingOperationalClearanceTable
{
    public static function configure(Table $table, bool $showClearanceKindColumn = true): Table
    {
        $columns = [
            TextColumn::make('transaction_date')
                ->date()
                ->sortable(),
        ];

        if ($showClearanceKindColumn) {
            $columns[] = TextColumn::make('clearance_kind')
                ->label(__('Type'))
                ->badge()
                ->searchable(false)
                ->sortable(false)
                ->state(fn (BankTransaction $record): string => match (true) {
                    $record->invest_return_id !== null => __('Return in'),
                    $record->invest_disbursement_id !== null => __('Invest out'),
                    $record->fee_disbursement_id !== null => __('Fee'),
                    $record->expense_disbursement_id !== null => __('Expense'),
                    $record->cash_out_request_id !== null => __('Cash out'),
                    default => __('Deposit'),
                })
                ->color(fn (BankTransaction $record): string => match (true) {
                    $record->invest_return_id !== null => 'success',
                    $record->invest_disbursement_id !== null => 'warning',
                    $record->fee_disbursement_id !== null => 'info',
                    $record->expense_disbursement_id !== null => 'danger',
                    $record->cash_out_request_id !== null => 'warning',
                    default => 'success',
                });
        }

        $columns = array_merge($columns, [
            TextColumn::make('member.name')
                ->label(__('Member'))
                ->sortable(),
            TextColumn::make('amount')
                ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                ->sortable()
                ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger'),
            TextColumn::make('description')
                ->searchable()
                ->wrap(),
            TextColumn::make('status')
                ->badge()
                ->formatStateUsing(fn (string $state): string => BankTransaction::statusOptions()[$state] ?? $state)
                ->color(fn (string $state): string => match ($state) {
                    'imported' => 'warning',
                    'posted' => 'success',
                    default => 'gray',
                }),
        ]);

        return TableGrouping::apply(
            $table
                ->columns($columns)
                ->filters([
                    SelectFilter::make('member_id')
                        ->label(__('Member'))
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                ])
                ->recordUrl(fn (): ?string => null)
                ->recordAction(ViewAction::getDefaultName())
                ->emptyStateDescription(__('Accepted deposits, cash-outs, and disbursements awaiting bank evidence. Match pairs an imported CSV line; clear closes the row when no import line is available.'))
                ->recordActions(BankClearingQueueActions::groupedRecordActions(BankClearingTabRegistry::FILTER_OPERATIONS))
                ->toolbarActions([
                    BulkActionGroup::make(
                        BankClearingQueueActions::toolbarBulkActions(BankClearingTabRegistry::FILTER_OPERATIONS),
                    ),
                    TableToolbar::refreshBulkAction(),
                ])
                ->defaultSort('transaction_date', 'desc'),
            TableGrouping::bankTransactions()
        );
    }

    public static function configurePreview(Table $table, bool $showClearanceKindColumn = true): Table
    {
        return self::configure($table, $showClearanceKindColumn)
            ->recordActions(TableRecordActionGroups::wrap([
                ViewBankTransactionAction::make(),
            ]))
            ->toolbarActions(TableStandards::defaultToolbarActions());
    }
}
