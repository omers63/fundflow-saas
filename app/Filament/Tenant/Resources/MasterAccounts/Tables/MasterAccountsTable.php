<?php

namespace App\Filament\Tenant\Resources\MasterAccounts\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Models\Tenant\Setting;
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
                ->sortable(),
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
                    default => 'gray',
                });
        }

        $columns[] = TextColumn::make('balance')
            ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
            ->sortable();

        $columns[] = TextColumn::make('updated_at')
            ->label(__('Last activity'))
            ->dateTime()
            ->sortable();

        return $table
            ->columns($columns)
            ->filters([
                DateColumnRangeFilter::make('updated_at', __('Last activity')),
            ])
            ->recordUrl(fn (Model $record): string => MasterAccountResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('name');
    }
}
