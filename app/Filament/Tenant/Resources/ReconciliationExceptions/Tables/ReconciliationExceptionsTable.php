<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\ReconciliationExceptions\Tables;

use App\Filament\Support\MoneyDisplay;
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
    public static function configure(
        Table $table,
        bool $queueOnly = false,
        bool $advancedUi = false,
        bool $workspacePanel = false,
        ?int $selectedExceptionId = null,
    ): Table {
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
            ->columns(self::columns($advancedUi, $workspacePanel))
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

        if ($workspacePanel) {
            $table = $table
                ->recordAction('selectException')
                ->recordClasses(function (ReconciliationException $record) use ($selectedExceptionId): array {
                    if ($selectedExceptionId === null) {
                        return [];
                    }

                    return (int) $record->getKey() === $selectedExceptionId
                        ? ['ff-recon-exception-row--selected']
                        : [];
                })
                ->recordActions(TableRecordActionGroups::wrap(
                    ReconciliationExceptionActions::recordActionsForMode($advancedUi),
                ));
        } else {
            $table = $table
                ->recordAction('viewException')
                ->recordActions(TableRecordActionGroups::wrap(
                    ReconciliationExceptionActions::recordActionsForMode($advancedUi),
                ));
        }

        return TableGrouping::apply($table
            ->toolbarActions([
                BulkActionGroup::make([
                    ReconciliationExceptionActions::deleteBulkAction(),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::reconciliationExceptions());
    }

    /**
     * @return array<int, TextColumn>
     */
    private static function columns(bool $advancedUi, bool $workspacePanel): array
    {
        $columns = [
            TextColumn::make('exception_code')
                ->label(__('Issue'))
                ->searchable()
                ->wrap()
                ->description(function (ReconciliationException $record) use ($advancedUi, $workspacePanel): ?string {
                    if ($workspacePanel) {
                        return ReconciliationExceptionPresenter::summary($record);
                    }

                    return $advancedUi ? $record->exception_code : null;
                })
                ->formatStateUsing(fn (string $state, ReconciliationException $record): string => ReconciliationExceptionPresenter::title($record)),
            TextColumn::make('domain')
                ->label(__('Area'))
                ->badge()
                ->formatStateUsing(fn (string $state): string => ReconciliationExceptionPresenter::domainLabel($state)),
            TextColumn::make('severity')
                ->badge()
                ->color(fn (string $state): string => ReconciliationExceptionPresenter::severityStyle($state)['badge']),
        ];

        if ($workspacePanel) {
            $columns[] = TextColumn::make('recommended_step')
                ->label(__('Next step'))
                ->state(fn (ReconciliationException $record): string => ReconciliationExceptionPresenter::recommendedAction($record))
                ->wrap()
                ->limit(90)
                ->searchable(false)
                ->sortable(false)
                ->toggleable();
            $columns[] = TextColumn::make('context_preview')
                ->label(__('Context'))
                ->state(fn (ReconciliationException $record): string => implode(' · ', ReconciliationExceptionPresenter::contextPreview($record)))
                ->placeholder(__('—'))
                ->wrap()
                ->searchable(false)
                ->sortable(false)
                ->toggleable(isToggledHiddenByDefault: true);
        }

        $columns = array_merge($columns, [
            TextColumn::make('amount_delta')
                ->label(__('Delta'))
                ->formatStateUsing(fn (?string $state): string => filled($state)
                    ? (MoneyDisplay::format((float) $state) ?? '—')
                    : '—')
                ->placeholder(__('—'))
                ->toggleable(isToggledHiddenByDefault: ! $advancedUi && ! $workspacePanel),
            TextColumn::make('status')
                ->badge()
                ->formatStateUsing(fn (string $state): string => ReconciliationExceptionPresenter::statusLabel($state))
                ->color(fn (string $state): string => match ($state) {
                    ReconciliationException::STATUS_OPEN => 'danger',
                    ReconciliationException::STATUS_ESCALATED => 'warning',
                    ReconciliationException::STATUS_RESOLVED => 'success',
                    default => 'gray',
                }),
            TextColumn::make('assignee.name')
                ->label(__('Owner'))
                ->placeholder(__('Unassigned'))
                ->toggleable(isToggledHiddenByDefault: ! $advancedUi && ! $workspacePanel),
            TextColumn::make('sla_deadline')
                ->label(__('SLA'))
                ->since()
                ->dateTimeTooltip()
                ->placeholder(__('—'))
                ->toggleable(isToggledHiddenByDefault: true)
                ->visible($advancedUi),
            TextColumn::make('raised_at')
                ->label(__('Raised'))
                ->since()
                ->dateTimeTooltip()
                ->sortable(),
            TextColumn::make('exception_type')
                ->label(__('Type'))
                ->placeholder(__('—'))
                ->toggleable(isToggledHiddenByDefault: true)
                ->visible($advancedUi),
            TextColumn::make('resolved_at')
                ->label(__('Resolved at'))
                ->dateTime()
                ->placeholder(__('—'))
                ->toggleable(isToggledHiddenByDefault: true)
                ->visible($advancedUi),
            TextColumn::make('resolution_notes')
                ->label(__('Resolution notes'))
                ->placeholder(__('—'))
                ->wrap()
                ->limit(80)
                ->toggleable(isToggledHiddenByDefault: true)
                ->visible($advancedUi),
        ]);

        return $columns;
    }
}
