<?php

namespace App\Filament\Tenant\Resources\MasterAccounts\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\Setting;
use App\Support\Insights\MasterInvestNetReturn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MasterAccountsTable
{
    public static function configure(Table $table, bool $showTypeColumn = false, string $activeTab = 'all'): Table
    {
        $columns = [
            TextColumn::make('name')
                ->label(__('Account'))
                ->formatStateUsing(fn (string $state, Account $record): string => $record->displayLabel())
                ->sortable(query: function ($query, string $direction): void {
                    $query->orderBy('name', $direction);
                }),
        ];

        if ($showTypeColumn) {
            $columns[] = TextColumn::make('type')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'cash' => 'info',
                    'fund' => 'success',
                    'bank' => 'primary',
                    'expense' => 'danger',
                    'fees' => 'warning',
                    'invest' => 'gray',
                    'suspense' => 'gray',
                    default => 'gray',
                })
                ->formatStateUsing(fn (string $state): string => MasterAccountResource::tabLabel($state));
        }

        $showsInvestNetReturn = $activeTab === 'invest' || $showTypeColumn;

        $balanceColumn = TextColumn::make('balance')
            ->label($activeTab === 'invest' ? __('Net return') : __('Balance'))
            ->state(function (Account $record) use ($showsInvestNetReturn): float {
                if ($showsInvestNetReturn && $record->type === 'invest') {
                    return MasterInvestNetReturn::netReturn($record);
                }

                return (float) $record->balance;
            })
            ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
            ->color(function (Account $record) use ($showsInvestNetReturn): ?string {
                if (! $showsInvestNetReturn || $record->type !== 'invest') {
                    return null;
                }

                $summary = MasterInvestNetReturn::summarize($record);

                return match (true) {
                    $summary['is_negative'] => 'danger',
                    $summary['net_return'] > 0 => 'success',
                    default => null,
                };
            })
            ->sortable();

        if ($showsInvestNetReturn) {
            $balanceColumn->summarize([]);
        }

        $columns[] = $balanceColumn;

        $columns[] = TextColumn::make('last_activity_at')
            ->label(__('Last activity'))
            ->dateTime()
            ->sortable();

        return TableGrouping::apply(
            $table
                ->columns($columns)
                ->filters([
                    DateColumnRangeFilter::forLastLedgerActivity(),
                ])
                ->recordUrl(fn (Model $record): string => MasterAccountResource::getUrl('view', ['record' => $record]))
                ->recordActions(TableRecordActionGroups::wrap([
                    ViewAction::make(),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('name'),
            TableGrouping::memberAccounts($showTypeColumn)
        );
    }
}
