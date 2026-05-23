<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundAuditLogs\Tables;

use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FundAuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply($table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label(__('Event'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('domain')
                    ->badge(),
                TextColumn::make('member.name')
                    ->label(__('Member'))
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('operator.name')
                    ->label(__('Operator'))
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('checksum')
                    ->label(__('Checksum'))
                    ->limit(12)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('domain')
                    ->options([
                        'reconciliation' => __('Reconciliation'),
                        'migration' => __('Migration'),
                        'ledger' => __('Ledger'),
                        'contribution' => __('Contribution'),
                        'loan' => __('Loan'),
                    ]),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                ViewAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::fundAuditLogs());
    }
}
