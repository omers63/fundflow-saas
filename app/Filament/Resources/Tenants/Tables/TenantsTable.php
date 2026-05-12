<?php

namespace App\Filament\Resources\Tenants\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->searchable()->copyable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('slug')->badge(),
                TextColumn::make('tenancy_db_name')->label('DB'),
                TextColumn::make('domains.domain')
                    ->label('Primary Domain')
                    ->limit(40),
                TextColumn::make('created_at')->since(),
            ])
            ->filters([
                //
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
