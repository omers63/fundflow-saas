<?php

namespace App\Filament\Tenant\Resources\MasterAccounts\RelationManagers;

use App\Filament\Concerns\OpensFocusedLedgerTransaction;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\AccountDetailInsightsRefresh;
use App\Filament\Support\AccountTransactionAmountColumn;
use App\Filament\Support\AccountTransactionDescriptionColumn;
use App\Filament\Support\AccountTransactionFlowReferenceColumn;
use App\Filament\Support\AccountTransactionLinkedSourceColumn;
use App\Filament\Support\AccountTransactionLinkedSourceFilter;
use App\Filament\Support\AccountTransactionManualAdjustmentHeaderActions;
use App\Filament\Support\AccountTransactionTypeColumn;
use App\Filament\Support\AccountTransactionTypeFilter;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MasterAccountLedgerHeaderActions;
use App\Filament\Support\MasterExpenseHeaderActions;
use App\Filament\Support\MasterFeesHeaderActions;
use App\Filament\Support\MasterInvestHeaderActions;
use App\Filament\Support\MemberSelect;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Models\Tenant\Account;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    use OpensFocusedLedgerTransaction;
    use TranslatesRelationManagerTitle;

    protected static bool $isLazy = false;

    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Transaction History';

    public function table(Table $table): Table
    {
        return ViewAccountTransactionAction::configure($table
            ->recordTitleAttribute('description')
            ->heading(__('Transaction history'))
            ->modifyQueryUsing(fn ($query) => $query->with(['member', 'account']))
            ->columns([
                TextColumn::make('transacted_at')
                    ->label(__('Date'))
                    ->dateTime()
                    ->sortable(),
                AccountTransactionFlowReferenceColumn::invest()
                    ->visible(fn (): bool => $this->getOwnerRecord()->is_master && $this->getOwnerRecord()->type === 'invest'),
                AccountTransactionFlowReferenceColumn::expense()
                    ->visible(fn (): bool => $this->getOwnerRecord()->is_master && $this->getOwnerRecord()->type === 'expense'),
                AccountTransactionTypeColumn::make(),
                AccountTransactionAmountColumn::make(),
                TextColumn::make('balance_after')
                    ->label(__('Balance'))
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                AccountTransactionDescriptionColumn::make(),
                AccountTransactionLinkedSourceColumn::make(),
                TextColumn::make('id')
                    ->label(__('Txn #'))
                    ->searchable()
                    ->sortable(),
                MemberTableColumns::relationNumber()
                    ->label(__('Member #'))
                    ->placeholder(__('—')),
                TextColumn::make('member.name')
                    ->label(__('Member tag'))
                    ->placeholder(__('—'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('bank_import')
                    ->label(__('Bank import'))
                    ->state(fn (Transaction $record): ?string => $record->bankImportSummary())
                    ->placeholder(__('—'))
                    ->searchable(false)
                    ->sortable(false)
                    ->wrap()
                    ->visible(fn (): bool => $this->getOwnerRecord()->is_master && $this->getOwnerRecord()->type === 'cash'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(AccountTransactionTypeFilter::options()),
                DateColumnRangeFilter::make('transacted_at', __('Date')),
                AccountTransactionLinkedSourceFilter::make(),
                MemberSelect::configureFilter(
                    SelectFilter::make('member_id')->label(__('Member tag')),
                ),
            ])
            ->defaultSort('transacted_at', 'desc'))
            ->toolbarActions(ViewAccountTransactionAction::tenantToolbarActions())
            ->headerActions([
                ...MasterAccountLedgerHeaderActions::importExport(
                    fn (): Account => $this->getOwnerRecord(),
                    $this->ledgerMutationAfter(),
                ),
                ...MasterAccountLedgerHeaderActions::wrap([
                    ...AccountTransactionManualAdjustmentHeaderActions::make(
                        fn (): Account => $this->getOwnerRecord(),
                        $this->ledgerMutationAfter(),
                    ),
                    ...MasterExpenseHeaderActions::make(
                        fn (): Account => $this->getOwnerRecord(),
                        $this->ledgerMutationAfter(),
                    ),
                    ...MasterInvestHeaderActions::make(
                        fn (): Account => $this->getOwnerRecord(),
                        $this->ledgerMutationAfter(),
                    ),
                    ...MasterFeesHeaderActions::make(
                        fn (): Account => $this->getOwnerRecord(),
                        $this->ledgerMutationAfter(),
                    ),
                ]),
            ]);
    }

    protected function ledgerMutationAfter(): \Closure
    {
        return function (): void {
            $this->resetTable();
            AccountDetailInsightsRefresh::dispatch($this, (int) $this->getOwnerRecord()->getKey());
        };
    }
}
