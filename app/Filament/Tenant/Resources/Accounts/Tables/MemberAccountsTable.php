<?php

namespace App\Filament\Tenant\Resources\Accounts\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Models\Tenant\Setting;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MemberAccountsTable
{
    public static function configure(Table $table, bool $showTypeColumn = false): Table
    {
        $columns = [
            TextColumn::make('name')
                ->searchable()
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

        $columns[] = TextColumn::make('member.name')
            ->label('Member')
            ->searchable()
            ->sortable();

        $columns[] = TextColumn::make('balance')
            ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
            ->sortable();

        $columns[] = TextColumn::make('updated_at')
            ->dateTime()
            ->sortable()
            ->toggledHiddenByDefault();

        return $table
            ->columns($columns)
            ->filters([
                SelectFilter::make('member_id')
                    ->label('Member')
                    ->relationship('member', 'name')
                    ->searchable()
                    ->preload(),
                DateColumnRangeFilter::make('updated_at', 'Last updated'),
            ])
            ->recordUrl(fn (Model $record): string => AccountResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('member_id');
    }
}
