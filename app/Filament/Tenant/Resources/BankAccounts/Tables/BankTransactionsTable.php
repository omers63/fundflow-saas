<?php

namespace App\Filament\Tenant\Resources\BankAccounts\Tables;

use App\Filament\Support\BankClearingQueueActions;
use App\Filament\Support\BankWorkspaceImportTableHeaderActions;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableStandards;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Filament\Tenant\Support\ViewBankTransactionAction;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Setting;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BankTransactionsTable
{
    public static function configure(Table $table, ?Closure $afterImport = null, bool $includeImportHeaderAction = true, bool $auditMode = false): Table
    {
        $recordActions = $auditMode
            ? TableRecordActionGroups::wrap([
                ViewBankTransactionAction::make(),
            ])
            : BankClearingQueueActions::groupedRecordActions(BankClearingTabRegistry::FILTER_BANK_FILE);

        $toolbarActions = $auditMode
            ? TableStandards::defaultToolbarActions()
            : [
                BulkActionGroup::make(
                    BankClearingQueueActions::toolbarBulkActions(BankClearingTabRegistry::FILTER_BANK_FILE),
                ),
            ];

        $table = TableGrouping::apply(
            $table
                ->columns([
                    TextColumn::make('transaction_date')
                        ->date()
                        ->sortable(),
                    TextColumn::make('bankStatement.filename')
                        ->label(__('Source'))
                        ->limit(20),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable()
                        ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger'),
                    MemberTableColumns::relationNumber()
                        ->placeholder(__('Unassigned')),
                    TextColumn::make('member.name')
                        ->label(__('Assigned to'))
                        ->placeholder(__('Unassigned'))
                        ->sortable(),
                    TextColumn::make('masterCashTransaction.id')
                        ->label(__('Master cash'))
                        ->placeholder(__('—'))
                        ->formatStateUsing(fn ($state, BankTransaction $record): string => $record->masterCashMirrorSummary() ?? __('—'))
                        ->wrap(),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => BankTransaction::statusOptions()[$state] ?? $state)
                        ->color(fn (string $state): string => match ($state) {
                            'imported' => 'warning',
                            'mirrored' => 'info',
                            'posted' => 'success',
                            'ignored' => 'gray',
                            'duplicate' => 'danger',
                        }),
                    TextColumn::make('duplicateOf.description')
                        ->label(__('Duplicate of'))
                        ->placeholder(__('—'))
                        ->limit(30)
                        ->toggledHiddenByDefault(),
                    IconColumn::make('is_cleared')
                        ->label(__('Cleared'))
                        ->boolean(),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options(BankTransaction::statusOptions()),
                    TernaryFilter::make('is_cleared')
                        ->label(__('Cleared status'))
                        ->trueLabel(__('Cleared'))
                        ->falseLabel(__('Uncleared')),
                    DateColumnRangeFilter::make('transaction_date', __('Transaction date')),
                    SelectFilter::make('member_id')
                        ->label(__('Member'))
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                    TernaryFilter::make('duplicate_of_id')
                        ->label(__('Duplicate link'))
                        ->nullable()
                        ->trueLabel(__('Linked'))
                        ->falseLabel(__('Not linked')),
                    SelectFilter::make('bank_statement_id')
                        ->label(__('Statement'))
                        ->relationship('bankStatement', 'filename')
                        ->searchable()
                        ->preload(),
                    Filter::make('description_contains')
                        ->label(__('Description'))
                        ->schema([
                            TextInput::make('value')
                                ->label(__('Contains')),
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
                ->emptyStateDescription($auditMode
                    ? __('Closed imported statement lines for audit. Resolve new items from the work queue.')
                    : __('Imported bank statement lines. Manual master bank credits and debits are on the Master bank ledger tab.'))
                ->recordActions($recordActions)
                ->toolbarActions($toolbarActions)
                ->defaultSort('transaction_date', 'desc'),
            TableGrouping::bankTransactions()
        );

        if ($includeImportHeaderAction) {
            $table->headerActions([
                BankWorkspaceImportTableHeaderActions::bankStatementImportAction($afterImport),
            ]);
        }

        return $table;
    }
}
