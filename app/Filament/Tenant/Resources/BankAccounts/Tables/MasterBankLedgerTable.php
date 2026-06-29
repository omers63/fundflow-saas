<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\BankAccounts\Tables;

use App\Filament\Support\AccountTransactionAmountColumn;
use App\Filament\Support\AccountTransactionManualAdjustmentHeaderActions;
use App\Filament\Support\AccountTransactionTypeColumn;
use App\Filament\Support\AccountTransactionTypeFilter;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MasterAccountLedgerHeaderActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use Closure;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MasterBankLedgerTable
{
    /**
     * @return array<int, Action>
     */
    public static function headerActions(?Closure $after = null): array
    {
        $masterBank = Account::masterBank();

        if ($masterBank === null) {
            return [];
        }

        return [
            ...MasterAccountLedgerHeaderActions::importExport(
                fn (): Account => $masterBank,
                $after,
            ),
            ...MasterAccountLedgerHeaderActions::wrap(
                AccountTransactionManualAdjustmentHeaderActions::make(
                    fn (): Account => $masterBank,
                    $after,
                ),
            ),
        ];
    }

    public static function configure(Table $table, ?Closure $afterLedgerMutation = null): Table
    {
        $masterBankId = Account::masterBank()?->id;

        return ViewAccountTransactionAction::configure(
            TableGrouping::apply(
                $table
                    ->query(
                        Transaction::query()
                            ->when(
                                $masterBankId !== null,
                                fn (Builder $query): Builder => $query->where('account_id', $masterBankId),
                                fn (Builder $query): Builder => $query->whereRaw('0 = 1'),
                            )
                            ->with('member'),
                    )
                    ->recordTitleAttribute('description')
                    ->columns([
                        TextColumn::make('transacted_at')
                            ->label(__('Date'))
                            ->dateTime()
                            ->sortable(),
                        AccountTransactionTypeColumn::make(),
                        AccountTransactionAmountColumn::make(),
                        TextColumn::make('balance_after')
                            ->label(__('Balance'))
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
                    ])
                    ->filters([
                        SelectFilter::make('type')
                            ->options(AccountTransactionTypeFilter::options()),
                        DateColumnRangeFilter::make('transacted_at', __('Date')),
                        SelectFilter::make('member_id')
                            ->label(__('Member tag'))
                            ->options(fn (): array => Member::query()
                                ->orderBy('member_number')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable(),
                    ])
                    ->defaultSort('transacted_at', 'desc')
                    ->emptyStateHeading(__('No ledger entries yet'))
                    ->emptyStateDescription(__('Manual credits and debits, bank clearing, and other master bank postings appear here. Imported statement lines are on the Statement lines tab.')),
                TableGrouping::accountTransactions(),
            )
                ->recordUrl(fn (): ?string => null)
                ->headerActions(self::headerActions($afterLedgerMutation))
                ->toolbarActions(ViewAccountTransactionAction::tenantToolbarActions()),
        );
    }
}
