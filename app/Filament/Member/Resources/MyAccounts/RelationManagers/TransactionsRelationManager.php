<?php

namespace App\Filament\Member\Resources\MyAccounts\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Support\AccountTransactionAmountColumn;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Models\Tenant\Setting;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Transaction History';

    public function table(Table $table): Table
    {
        return ViewAccountTransactionAction::configure($table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('transacted_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'credit' => 'success',
                        'debit' => 'danger',
                    }),
                AccountTransactionAmountColumn::make(),
                TextColumn::make('balance_after')
                    ->label('Balance')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('description')
                    ->searchable()
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'credit' => 'Credit',
                        'debit' => 'Debit',
                    ]),
                DateColumnRangeFilter::make('transacted_at', 'Date'),
            ])
            ->defaultSort('transacted_at', 'desc'));
    }
}
