<?php

namespace App\Filament\Resources\Plans\Tables;

use App\Models\Central\Plan;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('price')
                    ->money()
                    ->sortable(),
                TextColumn::make('currency')
                    ->searchable(),
                TextColumn::make('billing_cycle')
                    ->searchable(),
                TextColumn::make('duration_months')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean(),
                IconColumn::make('is_custom')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggledHiddenByDefault(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggledHiddenByDefault(),
            ])
            ->filters([
                SelectFilter::make('billing_cycle')
                    ->label('Billing cycle')
                    ->options(fn (): array => Plan::query()
                        ->whereNotNull('billing_cycle')
                        ->distinct()
                        ->orderBy('billing_cycle')
                        ->pluck('billing_cycle', 'billing_cycle')
                        ->all()),
                SelectFilter::make('currency')
                    ->options(fn (): array => Plan::query()
                        ->whereNotNull('currency')
                        ->distinct()
                        ->orderBy('currency')
                        ->pluck('currency', 'currency')
                        ->all()),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                TernaryFilter::make('is_custom')
                    ->label('Custom plan'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
