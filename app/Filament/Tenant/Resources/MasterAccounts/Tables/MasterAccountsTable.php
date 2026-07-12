<?php

namespace App\Filament\Tenant\Resources\MasterAccounts\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Support\UiLabelIcons;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\Setting;
use App\Support\Insights\MasterInvestNetReturn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class MasterAccountsTable
{
    public static function configure(Table $table): Table
    {
        $columns = [
            TextColumn::make('name')
                ->label(__('Account'))
                ->html()
                ->formatStateUsing(fn (string $state, Account $record): Htmlable => UiLabelIcons::labeledHtml(
                    $record->displayLabel(),
                    UiLabelIcons::forKey($record->type),
                ))
                ->sortable(query: function ($query, string $direction): void {
                    $query->orderBy('name', $direction);
                }),
            TextColumn::make('type')
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
                ->formatStateUsing(fn (string $state): string => MasterAccountResource::tabLabel($state)),
        ];

        $balanceColumn = TextColumn::make('balance')
            ->label(__('Balance'))
            ->state(function (Account $record): float {
                if ($record->type === 'invest') {
                    return MasterInvestNetReturn::netReturn($record);
                }

                return (float) $record->balance;
            })
            ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
            ->color(function (Account $record): ?string {
                if ($record->type !== 'invest') {
                    return null;
                }

                $summary = MasterInvestNetReturn::summarize($record);

                return match (true) {
                    $summary['is_negative'] => 'danger',
                    $summary['net_return'] > 0 => 'success',
                    default => null,
                };
            })
            ->sortable(false)
            ->summarize([]);

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
            TableGrouping::memberAccounts(includeType: true)
        );
    }
}
