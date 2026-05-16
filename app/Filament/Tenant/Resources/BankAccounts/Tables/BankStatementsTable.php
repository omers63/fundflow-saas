<?php

namespace App\Filament\Tenant\Resources\BankAccounts\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Models\Tenant\BankStatement;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class BankStatementsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply($table
            ->columns([
                TextColumn::make('filename')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bank_name')
                    ->placeholder(__('—'))
                    ->sortable(),
                TextColumn::make('statement_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('total_rows')
                    ->label('Total'),
                TextColumn::make('imported_rows')
                    ->label('Imported'),
                TextColumn::make('duplicate_rows')
                    ->label('Duplicates'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'processing' => 'info',
                        'completed' => 'success',
                        'failed' => 'danger',
                    }),
                TextColumn::make('imported_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
                SelectFilter::make('bank_name')
                    ->label('Bank')
                    ->options(fn (): array => BankStatement::query()
                        ->whereNotNull('bank_name')
                        ->where('bank_name', '!=', '')
                        ->distinct()
                        ->orderBy('bank_name')
                        ->pluck('bank_name', 'bank_name')
                        ->all()),
                DateColumnRangeFilter::make('statement_date', 'Statement date'),
                DateColumnRangeFilter::make('imported_at', 'Imported'),
            ])
            ->recordUrl(fn (Model $record): string => BankAccountsResource::getUrl('view', ['record' => $record]))
            ->recordActions(TableRecordActionGroups::wrap([
                ViewAction::make(),
                Action::make('delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Model $record): string => __('This will permanently delete ":filename" and all its :count transaction(s).', [
                        'filename' => $record->filename,
                        'count' => $record->transactions()->count(),
                    ]))
                    ->action(function (Model $record) {
                        $record->transactions()->delete();
                        $record->delete();
                        Notification::make()->title(__('Statement deleted'))->success()->send();
                    }),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('deleteSelected')
                        ->label(__('Delete'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription(__('This will permanently delete the selected statements and all their transactions.'))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                $record->transactions()->delete();
                                $record->delete();
                                $count++;
                            }
                            Notification::make()->title(__(':count statement(s) deleted', ['count' => $count]))->success()->send();
                        }),
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->defaultSort('imported_at', 'desc'),
            TableGrouping::bankStatements());
    }
}
