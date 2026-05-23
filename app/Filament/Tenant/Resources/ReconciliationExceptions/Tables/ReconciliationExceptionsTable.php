<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\ReconciliationExceptions\Tables;

use App\Filament\Support\ReconciliationExceptionActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\ReconciliationException;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReconciliationExceptionsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply($table
            ->modifyQueryUsing(fn ($query) => $query->with('assignee'))
            ->defaultSort('raised_at', 'desc')
            ->columns([
                TextColumn::make('exception_type')
                    ->label(__('Type'))
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('exception_code')
                    ->label(__('Code'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('domain')
                    ->badge(),
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('amount_delta')
                    ->label(__('Delta'))
                    ->numeric(decimalPlaces: 2)
                    ->placeholder(__('—')),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('sla_deadline')
                    ->dateTime()
                    ->placeholder(__('—')),
                TextColumn::make('raised_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ReconciliationException::STATUS_OPEN => __('Open'),
                        ReconciliationException::STATUS_RESOLVED => __('Resolved'),
                        ReconciliationException::STATUS_ESCALATED => __('Escalated'),
                    ]),
                SelectFilter::make('severity')
                    ->options([
                        'critical' => __('Critical'),
                        'high' => __('High'),
                        'medium' => __('Medium'),
                        'low' => __('Low'),
                    ]),
            ])
            ->recordActions(ReconciliationExceptionActions::recordActions())
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::reconciliationExceptions());
    }
}
