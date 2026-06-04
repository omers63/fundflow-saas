<?php

namespace App\Filament\Tenant\Resources\BankAccounts\Tables;

use App\Filament\Support\ActionModalFailure;
use App\Filament\Support\BankTransactionTableActions;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\ViewActions\ViewBankTransactionAction;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Setting;
use App\Services\BankClearingMatchService;
use App\Services\FundFlowService;
use App\Support\BankTransactionWorkflow;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class BankTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->columns([
                    TextColumn::make('transaction_date')
                        ->date()
                        ->sortable(),
                    TextColumn::make('bankStatement.filename')
                        ->label('Source')
                        ->limit(20),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable()
                        ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger'),
                    TextColumn::make('member.name')
                        ->label('Assigned to')
                        ->placeholder(__('Unassigned'))
                        ->sortable(),
                    TextColumn::make('masterCashTransaction.id')
                        ->label(__('Master cash'))
                        ->placeholder(__('—'))
                        ->formatStateUsing(fn ($state, BankTransaction $record): string => $record->masterCashMirrorSummary() ?? __('—'))
                        ->wrap(),
                    TextColumn::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'imported' => 'warning',
                            'mirrored' => 'info',
                            'posted' => 'success',
                            'ignored' => 'gray',
                            'duplicate' => 'danger',
                        }),
                    TextColumn::make('duplicateOf.description')
                        ->label('Duplicate of')
                        ->placeholder(__('—'))
                        ->limit(30)
                        ->toggledHiddenByDefault(),
                    IconColumn::make('is_cleared')
                        ->label('Cleared')
                        ->boolean(),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options([
                            'imported' => 'Imported',
                            'mirrored' => 'Mirrored',
                            'posted' => 'Posted',
                            'ignored' => 'Ignored',
                            'duplicate' => 'Duplicate',
                        ]),
                    TernaryFilter::make('is_cleared')
                        ->label('Cleared status')
                        ->trueLabel(__('Cleared'))
                        ->falseLabel(__('Uncleared')),
                    DateColumnRangeFilter::make('transaction_date', 'Transaction date'),
                    SelectFilter::make('member_id')
                        ->label('Member')
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                    TernaryFilter::make('duplicate_of_id')
                        ->label('Duplicate link')
                        ->nullable()
                        ->trueLabel(__('Linked'))
                        ->falseLabel(__('Not linked')),
                    SelectFilter::make('bank_statement_id')
                        ->label('Statement')
                        ->relationship('bankStatement', 'filename')
                        ->searchable()
                        ->preload(),
                    Filter::make('description_contains')
                        ->label('Description')
                        ->schema([
                            TextInput::make('value')
                                ->label('Contains'),
                        ])
                        ->query(function (Builder $query, array $data): Builder {
                            $needle = $data['value'] ?? null;

                            return $query->when(
                                filled($needle),
                                fn (Builder $query): Builder => $query->where('description', 'like', '%'.addcslashes((string) $needle, '%_\\').'%'),
                            );
                        })
                        ->indicateUsing(function (array $data): array {
                            if (! filled($data['value'] ?? null)) {
                                return [];
                            }

                            return [
                                Indicator::make(__('Description: :value', ['value' => $data['value']]))
                                    ->removeField('value'),
                            ];
                        }),
                ])
                ->recordUrl(fn (): ?string => null)
                ->recordAction(ViewAction::getDefaultName())
                ->emptyStateDescription(__('Imported bank statement lines. Manual master bank credits and debits are on the Master bank ledger tab.'))
                ->recordActions(TableRecordActionGroups::wrap([
                    ViewBankTransactionAction::make(),
                    Action::make('mirrorToCash')
                        ->label(__('Post to cash'))
                        ->icon('heroicon-o-arrow-right')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalDescription(__('Post this statement line to the master cash pool.'))
                        ->hidden(fn (BankTransaction $record): bool => ! BankTransactionWorkflow::canPostToCash($record))
                        ->action(function ($record, FundFlowService $service) {
                            $service->mirrorToCash([$record->id]);
                            Notification::make()->title(__('Posted to master cash'))->success()->send();
                        }),
                    BankTransactionTableActions::postToMember(),
                    Action::make('clearMatch')
                        ->label('Clear / Match')
                        ->icon('heroicon-o-link')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalDescription(__('Match this uncleared transaction against an imported bank transaction to clear both.'))
                        ->hidden(fn (BankTransaction $record, BankClearingMatchService $matching): bool => ! $matching->isPendingClearance($record)
                            || $matching->isSyntheticOperationalStatement($record))
                        ->form([
                            Select::make('imported_transaction_id')
                                ->label(__('Match with bank statement line'))
                                ->options(function (BankTransaction $record, BankClearingMatchService $matching): array {
                                    return $matching->findImportedCandidates($record)
                                        ->mapWithKeys(fn (BankTransaction $txn): array => [
                                            $txn->id => $matching->formatMatchOptionLabel($txn),
                                        ])
                                        ->all();
                                })
                                ->searchable()
                                ->required()
                                ->helperText(__('Only imported CSV statement lines within amount and date tolerance are listed.')),
                        ])
                        ->action(function (BankTransaction $record, array $data, Action $action, BankClearingMatchService $matching): void {
                            $imported = BankTransaction::findOrFail($data['imported_transaction_id']);

                            if (! $matching->isImportedMatchCandidate($imported)) {
                                ActionModalFailure::present(
                                    $action,
                                    __('Choose a bank import line that is not already linked to a posting.'),
                                    __('That statement line cannot be matched'),
                                );
                            }

                            $matching->clearMatchPair($record, $imported);

                            Notification::make()->title(__('Transactions matched and cleared'))->success()->send();
                        }),
                    Action::make('ignore')
                        ->label('Ignore')
                        ->icon('heroicon-o-x-mark')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->hidden(fn ($record) => $record->status !== 'imported')
                        ->action(function ($record) {
                            $record->update(['status' => 'ignored']);
                            Notification::make()->title(__('Transaction ignored'))->send();
                        }),
                    BankTransactionTableActions::delete(),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        BulkAction::make('clearMatchSelected')
                            ->label(__('Clear / match'))
                            ->icon('heroicon-o-link')
                            ->color('primary')
                            ->requiresConfirmation()
                            ->modalDescription(__('Match uncleared postings to imported statement lines. Select one pending and one imported line to pair directly, or select multiple lines to auto-match when amount and date uniquely identify a pair.'))
                            ->action(function (Collection $records, BankClearingMatchService $matching): void {
                                $stats = $matching->autoMatchSelected($records);

                                if ($stats['manual_pair'] && $stats['matched'] === 1) {
                                    Notification::make()
                                        ->title(__('Transactions matched and cleared'))
                                        ->success()
                                        ->send();

                                    return;
                                }

                                if ($stats['matched'] === 0 && $stats['ambiguous'] === 0 && $stats['skipped'] > 0) {
                                    Notification::make()
                                        ->title(__('No lines could be matched'))
                                        ->body(__('Selected rows must be uncleared postings or imported statement lines with a unique counterpart within tolerance.'))
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                $body = collect([
                                    $stats['matched'] > 0
                                    ? __(':count matched', ['count' => $stats['matched']])
                                    : null,
                                    $stats['ambiguous'] > 0
                                    ? __(':count ambiguous (multiple candidates)', ['count' => $stats['ambiguous']])
                                    : null,
                                    $stats['skipped'] > 0
                                    ? __(':count skipped', ['count' => $stats['skipped']])
                                    : null,
                                ])->filter()->implode(' · ');

                                Notification::make()
                                    ->title(__('Clear / match finished'))
                                    ->body($body)
                                    ->success()
                                    ->send();
                            }),
                        BulkAction::make('mirrorSelectedToCash')
                            ->label(__('Post to cash'))
                            ->icon('heroicon-o-arrow-right')
                            ->color('info')
                            ->requiresConfirmation()
                            ->modalDescription(__('Post all selected imported statement lines to the master cash pool.'))
                            ->action(function (Collection $records, FundFlowService $service) {
                                $importedIds = $records
                                    ->filter(fn (BankTransaction $record): bool => BankTransactionWorkflow::canPostToCash($record))
                                    ->pluck('id');
                                if ($importedIds->isEmpty()) {
                                    Notification::make()->title(__('No imported transactions selected'))->warning()->send();

                                    return;
                                }
                                $count = $service->mirrorToCash($importedIds);
                                Notification::make()->title(__(':count transaction(s) posted to master cash', ['count' => $count]))->success()->send();
                            }),
                        BankTransactionTableActions::postToMemberBulk(),
                        BulkAction::make('ignoreSelected')
                            ->label('Ignore selected')
                            ->icon('heroicon-o-x-mark')
                            ->color('gray')
                            ->requiresConfirmation()
                            ->action(function (Collection $records) {
                                $count = 0;
                                foreach ($records as $record) {
                                    if ($record->status === 'imported') {
                                        $record->update(['status' => 'ignored']);
                                        $count++;
                                    }
                                }
                                Notification::make()->title(__(':count transaction(s) ignored', ['count' => $count]))->send();
                            }),
                        BankTransactionTableActions::deleteBulk(),
                    ]),
                ])
                ->defaultSort('transaction_date', 'desc'),
            TableGrouping::bankTransactions()
        );
    }
}
