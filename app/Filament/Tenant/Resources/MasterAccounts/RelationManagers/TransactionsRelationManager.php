<?php

namespace App\Filament\Tenant\Resources\MasterAccounts\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\AccountDetailInsightsRefresh;
use App\Filament\Support\AccountTransactionAmountColumn;
use App\Filament\Support\AccountTransactionManualAdjustmentHeaderActions;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MasterExpenseHeaderActions;
use App\Filament\Support\MasterFeesHeaderActions;
use App\Filament\Support\MasterInvestHeaderActions;
use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Transaction History';

    public function table(Table $table): Table
    {
        return ViewAccountTransactionAction::configure($table
            ->recordTitleAttribute('description')
            ->modifyQueryUsing(fn ($query) => $query->with('member'))
            ->columns([
                TextColumn::make('transacted_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'credit' => 'success',
                        'debit' => 'danger',
                    }),
                AccountTransactionAmountColumn::make(),
                TextColumn::make('balance_after')
                    ->label('Balance')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('description')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('member.name')
                    ->label(__('Member tag'))
                    ->placeholder(__('—'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('bank_import')
                    ->label(__('Bank import'))
                    ->state(fn (Transaction $record): ?string => $record->bankImportSummary())
                    ->placeholder(__('—'))
                    ->wrap()
                    ->visible(fn (): bool => $this->getOwnerRecord()->is_master && $this->getOwnerRecord()->type === 'cash'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'credit' => 'Credit',
                        'debit' => 'Debit',
                    ]),
                DateColumnRangeFilter::make('transacted_at', 'Date'),
                SelectFilter::make('member_id')
                    ->label(__('Member tag'))
                    ->options(fn (): array => Member::query()
                        ->orderBy('member_number')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
            ])
            ->defaultSort('transacted_at', 'desc'))
            ->toolbarActions(ViewAccountTransactionAction::tenantToolbarActions())
            ->headerActions([
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
