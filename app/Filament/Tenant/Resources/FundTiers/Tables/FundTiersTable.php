<?php

namespace App\Filament\Tenant\Resources\FundTiers\Tables;

use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanQueueOrderingService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FundTiersTable
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
                TextColumn::make('loanTier.label')
                    ->label(__('Loan tier'))
                    ->placeholder(__('—')),
                TextColumn::make('percentage')
                    ->suffix('%'),
                TextColumn::make('declared_pool')
                    ->label(__('Declared pool'))
                    ->state(fn (FundTier $record): float => $record->allocated_amount)
                    ->money($currency)
                    ->sortable(false),
                TextColumn::make('tier_available')
                    ->label(__('Available'))
                    ->state(fn (FundTier $record): float => $record->available_amount)
                    ->money($currency)
                    ->sortable(false),
                TextColumn::make('active_loans_count')
                    ->label(__('Active loans'))
                    ->state(fn (FundTier $record): int => $record->active_loans_count)
                    ->sortable(false),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('tier_number')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                EditAction::make(),
                Action::make('resequence')
                    ->label(__('Resequence queue'))
                    ->icon('heroicon-o-arrows-up-down')
                    ->action(function (FundTier $record): void {
                        LoanQueueOrderingService::resequenceFundTier($record->id);
                        Notification::make()->title(__('Queue resequenced'))->success()->send();
                    }),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]);
    }
}
