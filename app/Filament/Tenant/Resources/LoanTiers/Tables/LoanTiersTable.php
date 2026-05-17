<?php

namespace App\Filament\Tenant\Resources\LoanTiers\Tables;

use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LoanTiersTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $table
            ->columnManager(true)
            ->columns([
                TextColumn::make('tier_number')
                    ->sortable(),
                TextColumn::make('label'),
                TextColumn::make('min_amount')
                    ->money($currency),
                TextColumn::make('max_amount')
                    ->money($currency),
                TextColumn::make('min_monthly_installment')
                    ->label(__('Min installment'))
                    ->money($currency),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('tier_number')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                EditAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]);
    }
}
