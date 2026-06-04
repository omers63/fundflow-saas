<?php

namespace App\Filament\Tenant\Resources\Contributions\Tables;

use App\Filament\Support\ContributionListTableHeaderActions;
use App\Filament\Support\ContributionTableActions;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContributionsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->headerActions(ContributionListTableHeaderActions::ledger())
                ->columns([
                    TextColumn::make('member.name')
                        ->searchable()
                        ->sortable(),
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
                        })
                        ->description(fn (Contribution $record): ?string => $record->status === 'failed'
                            ? __('Insufficient member cash when posting was attempted.')
                            : null),
                    TextColumn::make('posted_at')
                        ->dateTime()
                        ->placeholder(__('Not posted')),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options([
                            'pending' => 'Pending',
                            'posted' => 'Posted',
                            'failed' => 'Failed',
                        ]),
                    SelectFilter::make('member_id')
                        ->label('Member')
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                    DateColumnRangeFilter::make('period', 'Contribution period'),
                    DateColumnRangeFilter::make('posted_at', 'Posted'),
                ])
                ->recordActions(TableRecordActionGroups::wrap([
                    ContributionTableActions::delete(),
                    ContributionTableActions::post(),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        ContributionTableActions::deleteBulk(),
                        ContributionTableActions::postBulk(),
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('period', 'desc'),
            TableGrouping::contributions()
        );
    }
}
