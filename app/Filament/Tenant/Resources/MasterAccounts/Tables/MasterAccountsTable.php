<?php

namespace App\Filament\Tenant\Resources\MasterAccounts\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MasterAccountsTable
{
    public static function configure(Table $table, bool $showTypeColumn = false): Table
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

        $columns[] = TextColumn::make('balance')
            ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
            ->sortable();

        $columns[] = TextColumn::make('updated_at')
            ->label(__('Last activity'))
            ->dateTime()
            ->sortable();

        return TableGrouping::apply(
            $table
                ->columns($columns)
                ->filters([
                    DateColumnRangeFilter::make('updated_at', __('Last activity')),
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
