<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\BankAccounts\Tables;

use App\Filament\Support\BankClearingQueueActions;
use App\Filament\Support\BankTransactionTableActions;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Setting;
use App\Services\BankClearingQueueService;
use App\Support\BankClearing\BankClearingQueueKind;
use App\Support\BankClearing\BankClearingQueuePresenter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class BankClearingQueueTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->columns([
                    TextColumn::make('transaction_date')
                        ->date()
                        ->sortable(),
                    TextColumn::make('queue_slice')
                        ->label(__('Source'))
                        ->badge()
                        ->searchable(false)
                        ->sortable(false)
                        ->state(fn (BankTransaction $record): string => BankClearingQueuePresenter::sliceLabel($record))
                        ->color(fn (BankTransaction $record): string => BankClearingQueuePresenter::sliceColor($record)),
                    TextColumn::make('queue_kind')
                        ->label(__('Kind'))
                        ->badge()
                        ->searchable(false)
                        ->sortable(false)
                        ->state(fn (BankTransaction $record): string => BankClearingQueuePresenter::kindLabel($record))
                        ->color(fn (BankTransaction $record): string => BankClearingQueuePresenter::kindColor($record)),
                    TextColumn::make('bankStatement.filename')
                        ->label(__('Statement'))
                        ->limit(20)
                        ->placeholder(__('—'))
                        ->toggleable(),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable()
                        ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger'),
                    TextColumn::make('member.name')
                        ->label(__('Member'))
                        ->placeholder(__('Unassigned'))
                        ->sortable(),
                    TextColumn::make('description')
                        ->searchable()
                        ->wrap(),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => BankTransaction::statusOptions()[$state] ?? $state)
                        ->color(fn (string $state): string => match ($state) {
                            'imported' => 'warning',
                            'mirrored' => 'info',
                            'posted' => 'success',
                            default => 'gray',
                        }),
                    TextColumn::make('suggested_action')
                        ->label(__('Next step'))
                        ->searchable(false)
                        ->sortable(false)
                        ->state(fn (BankTransaction $record): string => BankClearingQueuePresenter::suggestedActionLabel($record) ?? __('—'))
                        ->color('gray')
                        ->toggleable(isToggledHiddenByDefault: true),
                ])
                ->filters([
                    SelectFilter::make('queue_slice')
                        ->label(__('Source'))
                        ->options(BankClearingQueueService::sliceFilterOptions())
                        ->query(function (Builder $query, array $data): Builder {
                            if (blank($data['value'] ?? null)) {
                                return $query;
                            }

                            return app(BankClearingQueueService::class)->applySliceFilter($query, (string) $data['value']);
                        }),
                    SelectFilter::make('queue_kind')
                        ->label(__('Kind'))
                        ->options(BankClearingQueueKind::filterOptions())
                        ->query(function (Builder $query, array $data): Builder {
                            if (blank($data['value'] ?? null)) {
                                return $query;
                            }

                            return app(BankClearingQueueService::class)->applyKindFilter($query, (string) $data['value']);
                        }),
                    SelectFilter::make('member_id')
                        ->label(__('Member'))
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                    DateColumnRangeFilter::make('transaction_date', __('Transaction date')),
                ])
                ->recordUrl(fn (): ?string => null)
                ->recordAction(ViewAction::getDefaultName())
                ->emptyStateDescription(__('Open bank file lines and operational rows that need posting or bank matching. Match pairs an imported CSV line; clear closes operational rows without bank evidence.'))
                ->recordActions(TableRecordActionGroups::wrap(BankClearingQueueActions::groupedRecordActions()))
                ->toolbarActions([
                    BulkActionGroup::make([
                        BankClearingQueueActions::matchAllUniqueBulk(),
                        BankClearingQueueActions::matchSelectedBulk(),
                        BankClearingQueueActions::clearWithoutEvidenceBulk(),
                        BankClearingQueueActions::postToCashBulk(),
                        BankTransactionTableActions::postToMemberBulk(),
                        BankClearingQueueActions::ignoreBulk(),
                        BankClearingQueueActions::deleteBulk(),
                    ]),
                    TableToolbar::refreshBulkAction(),
                ])
                ->defaultSort('transaction_date', 'desc'),
            TableGrouping::bankTransactions()
        );
    }
}
