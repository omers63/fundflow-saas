<?php

namespace App\Filament\Member\Resources\MyContributions\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\LateSettledArrearsTableStyling;
use App\Filament\Support\TableGrouping;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Setting;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MyContributionsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->columns([
                    TextColumn::make('period')
                        ->date('M Y')
                        ->sortable(),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable(),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state, Contribution $record): string => LateSettledArrearsTableStyling::contributionStatusLabel($record))
                        ->color(fn (string $state, Contribution $record): string => LateSettledArrearsTableStyling::contributionStatusColor($record))
                        ->tooltip(fn (Contribution $record): ?string => LateSettledArrearsTableStyling::contributionWasSettledLate($record)
                            ? LateSettledArrearsTableStyling::eligibilityHint()
                            : null),
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
                ->defaultSort('period', 'desc')
                ->recordClasses(fn (Contribution $record): ?string => LateSettledArrearsTableStyling::contributionRecordClasses($record)),
            TableGrouping::contributions(includeMember: false)
        );
    }
}
