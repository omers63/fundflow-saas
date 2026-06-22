<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\BankAccounts\Tables;

use App\Filament\Support\ActionModalFailure;
use App\Filament\Support\BankTransactionTableActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Tenant\Support\ViewBankTransactionAction;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Setting;
use App\Services\BankClearingMatchService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

final class PendingOperationalClearanceTable
{
    public static function configure(Table $table, bool $showClearanceKindColumn = true): Table
    {
        $columns = [
            TextColumn::make('transaction_date')
                ->date()
                ->sortable(),
        ];

        if ($showClearanceKindColumn) {
            $columns[] = TextColumn::make('clearance_kind')
                ->label(__('Type'))
                ->badge()
                ->searchable(false)
                ->sortable(false)
                ->state(fn(BankTransaction $record): string => match (true) {
                    $record->invest_return_id !== null => __('Return in'),
                    $record->invest_disbursement_id !== null => __('Invest out'),
                    $record->fee_disbursement_id !== null => __('Fee'),
                    $record->expense_disbursement_id !== null => __('Expense'),
                    $record->cash_out_request_id !== null => __('Cash out'),
                    default => __('Deposit'),
                })
                ->color(fn(BankTransaction $record): string => match (true) {
                    $record->invest_return_id !== null => 'success',
                    $record->invest_disbursement_id !== null => 'warning',
                    $record->fee_disbursement_id !== null => 'info',
                    $record->expense_disbursement_id !== null => 'danger',
                    $record->cash_out_request_id !== null => 'warning',
                    default => 'success',
                });
        }

        $columns = array_merge($columns, [
            TextColumn::make('member.name')
                ->label(__('Member'))
                ->sortable(),
            TextColumn::make('amount')
                ->money(fn(): string => Setting::get('general', 'currency', 'USD'))
                ->sortable()
                ->color(fn($state): string => $state >= 0 ? 'success' : 'danger'),
            TextColumn::make('description')
                ->searchable()
                ->wrap(),
            TextColumn::make('status')
                ->badge()
                ->formatStateUsing(fn(string $state): string => BankTransaction::statusOptions()[$state] ?? $state)
                ->color(fn(string $state): string => match ($state) {
                    'imported' => 'warning',
                    'posted' => 'success',
                    default => 'gray',
                }),
        ]);

        return TableGrouping::apply(
            $table
                ->columns($columns)
                ->filters([
                    SelectFilter::make('member_id')
                        ->label(__('Member'))
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                ])
                ->recordUrl(fn(): ?string => null)
                ->recordAction(ViewAction::getDefaultName())
                ->emptyStateDescription(__('Accepted deposits, cash-outs, expense disbursements, and fee disbursements that still need a matching line from an imported bank statement. After you match, they leave this list.'))
                ->recordActions(TableRecordActionGroups::wrap([
                    ViewBankTransactionAction::make(),
                    Action::make('clearMatch')
                        ->label(__('Clear / Match'))
                        ->icon('heroicon-o-link')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalDescription(__('Match this entry against an imported bank statement line on the Statement lines tab.'))
                        ->form([
                            Select::make('imported_transaction_id')
                                ->label(__('Match with bank statement line'))
                                ->options(function (BankTransaction $record, BankClearingMatchService $matching): array {
                                    return $matching->findImportedCandidates($record)
                                        ->mapWithKeys(fn(BankTransaction $txn): array => [
                                            $txn->id => $matching->formatMatchOptionLabel($txn),
                                        ])
                                        ->all();
                                })
                                ->searchable()
                                ->required()
                                ->helperText(__('Only real CSV statement lines within amount and date tolerance are listed.')),
                        ])
                        ->action(function (BankTransaction $record, array $data, Action $action, BankClearingMatchService $matching): void {
                            $imported = BankTransaction::findOrFail($data['imported_transaction_id']);

                            if (!$matching->isImportedMatchCandidate($imported)) {
                                ActionModalFailure::present(
                                    $action,
                                    __('Choose a bank import line that is not already linked to a posting.'),
                                    __('That statement line cannot be matched'),
                                );
                            }

                            $matching->clearMatchPair($record, $imported);

                            Notification::make()->title(__('Transactions matched and cleared'))->success()->send();
                        }),
                    BankTransactionTableActions::deletePendingOperationalClearance(),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        BulkAction::make('clearMatchSelected')
                            ->label(__('Clear / match'))
                            ->icon('heroicon-o-link')
                            ->color('primary')
                            ->requiresConfirmation()
                            ->modalDescription(__('Match selected pending entries to imported statement lines, or select one pending and one imported line to pair directly.'))
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
                                        ->body(__('Select uncleared deposits or cash-outs, or include a unique imported statement line.'))
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
                        BankTransactionTableActions::deletePendingOperationalClearanceBulk(),
                    ]),
                ])
                ->defaultSort('transaction_date', 'desc'),
            TableGrouping::bankTransactions()
        );
    }
}
