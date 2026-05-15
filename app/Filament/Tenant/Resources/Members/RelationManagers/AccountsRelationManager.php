<?php

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Support\DateColumnRangeFilter;
use App\Models\Tenant\Setting;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AccountsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'accounts';

    protected static ?string $title = 'Accounts';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'cash' => 'info',
                        'fund' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('balance')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'cash' => 'Cash',
                        'fund' => 'Fund',
                    ]),
                DateColumnRangeFilter::make('updated_at', 'Last updated'),
            ])
            ->defaultSort('type');
    }
}
