<?php

namespace App\Filament\Member\Resources\MyContributions\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Models\Tenant\Setting;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MyContributionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period')
                    ->date('M Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'posted' => 'success',
                        'failed' => 'danger',
                    }),
                TextColumn::make('posted_at')
                    ->dateTime()
                    ->placeholder(__('Not posted'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'posted' => 'Posted',
                        'failed' => 'Failed',
                    ]),
                DateColumnRangeFilter::make('period', 'Contribution period'),
                DateColumnRangeFilter::make('posted_at', 'Posted'),
            ])
            ->defaultSort('period', 'desc');
    }
}
