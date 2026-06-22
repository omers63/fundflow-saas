<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\ReconciliationExceptions\Tables;

use App\Filament\Support\ReconciliationExceptionActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\ReconciliationException;
use App\Support\Reconciliation\ReconciliationExceptionPresenter;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ReconciliationExceptionsTable
{
    public static function configure(Table $table, bool $queueOnly = false): Table
    {
        $table = $table
            ->modifyQueryUsing(function ($query) use ($queueOnly) {
                $query->with('assignee');

                if ($queueOnly) {
                    $query->whereIn('status', [
                        ReconciliationException::STATUS_OPEN,
                        ReconciliationException::STATUS_ESCALATED,
                    ]);
                }
            })
            ->defaultSort('raised_at', 'desc')
            ->columns([
                TextColumn::make('exception_code')
                    ->label(__('Issue'))
                    ->searchable()
                    ->wrap()
                    ->description(fn (ReconciliationException $record): string => $record->exception_code)
                    ->formatStateUsing(fn (string $state, ReconciliationException $record): string => ReconciliationExceptionPresenter::title($record)),
                TextColumn::make('domain')
                    ->label(__('Area'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ReconciliationExceptionPresenter::domainLabel($state)),
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('amount_delta')
                    ->label(__('Delta'))
                    ->numeric(decimalPlaces: 2)
                    ->placeholder(__('—')),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ReconciliationException::STATUS_OPEN => 'danger',
                        ReconciliationException::STATUS_ESCALATED => 'warning',
                        ReconciliationException::STATUS_RESOLVED => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('assignee.name')
                    ->label(__('Owner'))
                    ->placeholder(__('Unassigned'))
                    ->toggleable(),
                TextColumn::make('sla_deadline')
                    ->label(__('SLA'))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('raised_at')
                    ->label(__('Raised'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('exception_type')
                    ->label(__('Type'))
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('resolved_at')
                    ->label(__('Resolved at'))
                    ->dateTime()
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('resolution_notes')
                    ->label(__('Resolution notes'))
                    ->placeholder(__('—'))
                    ->wrap()
                    ->limit(80)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ReconciliationException::STATUS_OPEN => __('Open'),
                        ReconciliationException::STATUS_RESOLVED => __('Resolved'),
                        ReconciliationException::STATUS_ESCALATED => __('Escalated'),
                    ])
                    ->visible(! $queueOnly),
                SelectFilter::make('severity')
                    ->options([
                        'critical' => __('Critical'),
                        'high' => __('High'),
                        'medium' => __('Medium'),
                        'low' => __('Low'),
                    ]),
                SelectFilter::make('domain')
                    ->label(__('Area'))
                    ->options(ReconciliationExceptionPresenter::domainLabels()),
                TernaryFilter::make('assigned')
                    ->label(__('Assignment'))
                    ->nullable()
                    ->trueLabel(__('Assigned'))
                    ->falseLabel(__('Unassigned'))
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('assigned_to'),
                        false: fn ($query) => $query->whereNull('assigned_to'),
                    ),
            ]);

        return TableGrouping::apply($table
            ->recordAction('viewException')
            ->recordActions(TableRecordActionGroups::wrap([
                ReconciliationExceptionActions::viewAction(),
                ReconciliationExceptionActions::actionsMenu(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    ReconciliationExceptionActions::deleteBulkAction(),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::reconciliationExceptions());
    }
}
