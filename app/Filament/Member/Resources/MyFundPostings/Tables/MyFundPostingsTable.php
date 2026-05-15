<?php

namespace App\Filament\Member\Resources\MyFundPostings\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Models\Tenant\Setting;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MyFundPostingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('posting_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('reference')
                    ->placeholder(__('—')),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'accepted' => 'success',
                        'rejected' => 'danger',
                    }),
                TextColumn::make('admin_remarks')
                    ->label('Admin remarks')
                    ->placeholder(__('—'))
                    ->limit(40),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'accepted' => 'Accepted',
                        'rejected' => 'Rejected',
                    ]),
                DateColumnRangeFilter::make('posting_date', 'Posting date'),
                DateColumnRangeFilter::make('created_at', 'Submitted'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
