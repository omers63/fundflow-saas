<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Transactions\Tables;

use App\Filament\Support\AccountTransactionAmountColumn;
use App\Filament\Support\AccountTransactionDescriptionColumn;
use App\Filament\Support\AccountTransactionLinkedSourceColumn;
use App\Filament\Support\AccountTransactionLinkedSourceFilter;
use App\Filament\Support\AccountTransactionTypeColumn;
use App\Filament\Support\AccountTransactionTypeFilter;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableStandards;
use App\Filament\Support\TransactionBusinessTypeFilter;
use App\Filament\Support\UiLabelIcons;
use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Models\Tenant\Account;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Support\TransactionBusinessTypeCatalog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

final class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        $table = ViewAccountTransactionAction::configure(
            $table
                ->heading(UiLabelIcons::tableModelLabel(__('Transactions')))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['account', 'member', 'reference']))
                ->columns([
                    TextColumn::make('transacted_at')
                        ->label(__('Date'))
                        ->dateTime()
                        ->sortable(),
                    AccountTransactionAmountColumn::make(),
                    AccountTransactionDescriptionColumn::make(),
                    MemberTableColumns::relationNumber()
                        ->placeholder(__('—'))
                        ->toggleable(),
                    TextColumn::make('member.name')
                        ->label(__('Member'))
                        ->placeholder(__('—'))
                        ->searchable()
                        ->toggleable(),
                    TextColumn::make('account_scope')
                        ->label(__('Scope'))
                        ->state(fn (Transaction $record): string => $record->account?->is_master
                            ? __('Master')
                            : __('Member'))
                        ->searchable(false)
                        ->badge()
                        ->color(fn (Transaction $record): string => $record->account?->is_master ? 'primary' : 'gray')
                        ->sortable(query: function (Builder $query, string $direction): Builder {
                            $transactionTable = $query->getModel()->getTable();
                            $accountTable = (new Account)->getTable();

                            return $query->orderBy(
                                Account::query()
                                    ->select('is_master')
                                    ->whereColumn($accountTable.'.id', $transactionTable.'.account_id')
                                    ->limit(1),
                                $direction,
                            );
                        })
                        ->toggleable(),
                    TextColumn::make('account.type')
                        ->label(__('Account type'))
                        ->badge()
                        ->color(fn (?string $state): string => match ($state) {
                            'cash' => 'info',
                            'fund' => 'success',
                            'bank' => 'primary',
                            'expense' => 'danger',
                            'fees' => 'warning',
                            'invest' => 'gray',
                            'loan' => 'gray',
                            'suspense' => 'gray',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (?string $state): string => self::accountTypeLabel($state))
                        ->toggleable(),
                    TextColumn::make('business_type')
                        ->label(__('Transaction type'))
                        ->state(fn (Transaction $record): string => TransactionBusinessTypeCatalog::labelFor($record))
                        ->searchable(false)
                        ->sortable(false)
                        ->badge()
                        ->toggleable(),
                    TextColumn::make('account.name')
                        ->label(__('Account'))
                        ->html()
                        ->formatStateUsing(fn (?string $state, Transaction $record): Htmlable|string => $record->account === null
                            ? __('—')
                            : UiLabelIcons::labeledHtml(
                                $record->ledgerAccountLabel(),
                                UiLabelIcons::forKey((string) $record->account->type),
                            ))
                        ->searchable()
                        ->sortable(),
                    AccountTransactionTypeColumn::make(),
                    AccountTransactionLinkedSourceColumn::make(),
                    TextColumn::make('id')
                        ->label(__('Txn #'))
                        ->searchable()
                        ->sortable()
                        ->toggleable(),
                    TextColumn::make('balance_after')
                        ->label(__('Balance after'))
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                ])
                ->filters([
                    SelectFilter::make('account_class')
                        ->label(__('Scope'))
                        ->options([
                            'member' => __('Member'),
                            'master' => __('Master'),
                        ])
                        ->query(function (Builder $query, array $data): Builder {
                            return match ($data['value'] ?? null) {
                                'master' => $query->whereHas('account', fn (Builder $accountQuery): Builder => $accountQuery->where('is_master', true)),
                                'member' => $query->whereHas('account', fn (Builder $accountQuery): Builder => $accountQuery->where('is_master', false)),
                                default => $query,
                            };
                        }),
                    SelectFilter::make('type')
                        ->options(AccountTransactionTypeFilter::options()),
                    SelectFilter::make('account_type')
                        ->label(__('Account type'))
                        ->options(self::accountTypeOptions())
                        ->query(function (Builder $query, array $data): Builder {
                            $accountType = $data['value'] ?? null;

                            if (blank($accountType)) {
                                return $query;
                            }

                            return $query->whereHas('account', fn (Builder $accountQuery): Builder => $accountQuery->where('type', $accountType));
                        }),
                    TransactionBusinessTypeFilter::make(),
                    SelectFilter::make('member_id')
                        ->label(__('Member'))
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                    DateColumnRangeFilter::make('transacted_at', __('Date')),
                    AccountTransactionLinkedSourceFilter::make(),
                ])
                ->defaultSort('transacted_at', 'desc'),
            editable: false,
        )
            ->toolbarActions(TableStandards::defaultToolbarActions());

        return TableGrouping::apply($table, TableGrouping::globalTransactions());
    }

    /**
     * @return array<string, string>
     */
    private static function accountTypeOptions(): array
    {
        return [
            'cash' => __('Cash'),
            'fund' => __('Fund'),
            'bank' => __('Bank'),
            'expense' => __('Expense'),
            'fees' => __('Fees'),
            'invest' => __('Invest'),
            'loan' => __('Loan'),
            'suspense' => __('Suspense'),
        ];
    }

    private static function accountTypeLabel(?string $type): string
    {
        return self::accountTypeOptions()[$type ?? ''] ?? __('—');
    }
}
