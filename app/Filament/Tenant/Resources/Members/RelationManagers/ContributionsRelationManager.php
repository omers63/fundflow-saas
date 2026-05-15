<?php

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Support\DateColumnRangeFilter;
use App\Models\Tenant\Setting;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContributionsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'contributions';

    protected static ?string $title = 'Contributions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('period')
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
                        default => 'gray',
                    }),
                TextColumn::make('posted_at')
                    ->label('Posted')
                    ->dateTime()
                    ->placeholder(__('—'))
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
