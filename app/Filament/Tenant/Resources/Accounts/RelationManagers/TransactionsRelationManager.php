<?php

namespace App\Filament\Tenant\Resources\Accounts\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\AccountDetailInsightsRefresh;
use App\Filament\Support\AccountTransactionAmountColumn;
use App\Filament\Support\AccountTransactionManualAdjustmentHeaderActions;
use App\Filament\Support\AccountTransactionTypeColumn;
use App\Filament\Support\AccountTransactionTypeFilter;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Models\Tenant\Account;
use App\Models\Tenant\Setting;
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
        $table = ViewAccountTransactionAction::configure($table
            ->recordTitleAttribute('description')
            ->heading(__('Transaction history'))
            ->columns([
                TextColumn::make('transacted_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                AccountTransactionTypeColumn::make(),
                AccountTransactionAmountColumn::make(),
                TextColumn::make('balance_after')
                    ->label('Balance')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('description')
                    ->searchable()
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(AccountTransactionTypeFilter::options()),
                DateColumnRangeFilter::make('transacted_at', 'Date'),
            ])
            ->defaultSort('transacted_at', 'desc'))
            ->toolbarActions(ViewAccountTransactionAction::tenantToolbarActions())
            ->headerActions(AccountTransactionManualAdjustmentHeaderActions::make(
                fn (): Account => $this->getOwnerRecord(),
                $this->ledgerMutationAfter(),
            ));

        return $table;
    }

    protected function ledgerMutationAfter(): \Closure
    {
        return function (): void {
            $this->resetTable();
            AccountDetailInsightsRefresh::dispatch($this, (int) $this->getOwnerRecord()->getKey());
        };
    }
}
