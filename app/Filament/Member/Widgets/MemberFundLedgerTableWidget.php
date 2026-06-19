<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Filament\Support\AccountTransactionAmountColumn;
use App\Filament\Support\AccountTransactionTypeColumn;
use App\Filament\Support\AccountTransactionTypeFilter;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableToolbar;
use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class MemberFundLedgerTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public ?int $accountId = null;

    public function getHeading(): ?string
    {
        return __('Fund transactions');
    }

    protected function getTableQuery(): Builder
    {
        if ($this->accountId === null) {
            return Transaction::query()->whereRaw('1 = 0');
        }

        return Transaction::query()->where('account_id', $this->accountId);
    }

    public function table(Table $table): Table
    {
        return ViewAccountTransactionAction::configure($table
            ->heading(__('Fund transactions'))
            ->description(__('Contributions, loan fund legs, and other fund movements.'))
            ->columns([
                TextColumn::make('transacted_at')
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
                    ->wrap()
                    ->state(fn (Transaction $record): string => $record->memberFacingDescription()),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(AccountTransactionTypeFilter::options()),
                DateColumnRangeFilter::make('transacted_at', __('Date')),
            ])
            ->defaultSort('transacted_at', 'desc')
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), editable: false, memberPortal: true);
    }
}
