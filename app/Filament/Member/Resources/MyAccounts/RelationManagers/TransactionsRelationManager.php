<?php

namespace App\Filament\Member\Resources\MyAccounts\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\AccountTransactionAmountColumn;
use App\Filament\Support\AccountTransactionTypeColumn;
use App\Filament\Support\AccountTransactionTypeFilter;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableToolbar;
use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Transaction history';

    public function table(Table $table): Table
    {
        return ViewAccountTransactionAction::configure($table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('transacted_at')
                    ->label(__('Date'))
                    ->dateTime()
                    ->sortable(),
                AccountTransactionTypeColumn::make(),
                AccountTransactionAmountColumn::make(),
                TextColumn::make('balance_after')
                    ->label(__('Balance'))
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('description')
                    ->searchable()
                    ->wrap()
                    ->state(fn (Transaction $record): string => $record->memberFacingDescription()),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(AccountTransactionTypeFilter::options()),
                DateColumnRangeFilter::make('transacted_at', __('Date')),
            ])
            ->defaultSort('transacted_at', 'desc'), editable: false, memberPortal: true)
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]);
    }
}
