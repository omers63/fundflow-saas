<?php

namespace App\Filament\Tenant\Resources\Contributions\Tables;

use App\Filament\Support\ContributionListTableHeaderActions;
use App\Filament\Support\ContributionTableActions;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\LateSettledArrearsTableStyling;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
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
                ->headerActions(ContributionListTableHeaderActions::contributions())
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
                        ->formatStateUsing(fn (string $state, Contribution $record): string => LateSettledArrearsTableStyling::contributionStatusLabel($record))
                        ->color(fn (string $state, Contribution $record): string => LateSettledArrearsTableStyling::contributionStatusColor($record))
                        ->tooltip(fn (Contribution $record): ?string => LateSettledArrearsTableStyling::contributionWasSettledLate($record)
                            ? LateSettledArrearsTableStyling::eligibilityHint()
                            : null)
                        ->description(fn (Contribution $record): ?string => $record->status === 'failed'
                            ? __('Insufficient member cash when posting was attempted.')
                            : null),
                    TextColumn::make('late_fee_tier')
                        ->label(__('Late tier'))
                        ->badge()
                        ->formatStateUsing(fn (?int $state): string => $state ? __('Tier :n', ['n' => $state]) : '—')
                        ->color(fn (?int $state): string => match (true) {
                            $state === null || $state === 0 => 'gray',
                            $state === 1 => 'warning',
                            $state === 2 => 'info',
                            default => 'danger',
                        })
                        ->extraAttributes(fn (?int $state): array => $state === 2
                            ? ['class' => 'ff-late-fee-tier-2']
                            : [])
                        ->toggleable(isToggledHiddenByDefault: true)
                        ->sortable(),
                    TextColumn::make('posted_at')
                        ->dateTime()
                        ->placeholder(__('Not posted')),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options([
                            'pending' => __('Pending'),
                            'posted' => __('Posted'),
                            'failed' => __('Failed'),
                            'waived' => __('Waived'),
                        ]),
                    SelectFilter::make('member_id')
                        ->label('Member')
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                    DateColumnRangeFilter::make('period', 'Contribution period'),
                    DateColumnRangeFilter::make('posted_at', 'Posted'),
                ])
                ->recordClasses(fn (Contribution $record): ?string => LateSettledArrearsTableStyling::contributionRecordClasses($record))
                ->recordUrl(fn (Contribution $record): string => ContributionResource::getUrl('edit', ['record' => $record]))
                ->recordActions(TableRecordActionGroups::wrap([
                    ContributionTableActions::view(),
                    ContributionTableActions::edit(),
                    ContributionTableActions::post(),
                    ContributionTableActions::clearLatePosting(),
                    ContributionTableActions::delete(),
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
