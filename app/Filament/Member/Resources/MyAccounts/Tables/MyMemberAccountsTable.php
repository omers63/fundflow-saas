<?php

namespace App\Filament\Member\Resources\MyAccounts\Tables;

use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Support\DateColumnRangeFilter;
use App\Models\Tenant\Setting;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MyMemberAccountsTable
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
                DateColumnRangeFilter::make('updated_at', 'Last activity'),
            ])
            ->recordUrl(fn (Model $record): string => MyAccountResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
