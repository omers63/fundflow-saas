<?php

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\LateSettledArrearsTableStyling;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tables\Columns\TextColumn;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContributionsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'contributions';

    protected static ?string $title = 'Contributions';

    public function table(Table $table): Table
    {
        $currency = fn (): string => Setting::get('general', 'currency', 'USD');
        $lateFeeDescriptionPrefix = __('Contribution late fee —');

        return TableGrouping::apply(
            $table
                ->recordTitleAttribute('period')
                ->modifyQueryUsing(function (Builder $query) use ($lateFeeDescriptionPrefix): void {
                    $query->withSum([
                        'transactions as late_fee_collected_amount' => static function (Builder $transactionQuery) use ($lateFeeDescriptionPrefix): void {
                            $transactionQuery
                                ->where('type', 'debit')
                                ->where('description', 'like', $lateFeeDescriptionPrefix.'%');
                        },
                    ], 'amount');
                })
                ->columns([
                    TextColumn::make('period')
                        ->date('M Y')
                        ->sortable(),
                    TextColumn::make('amount')
                        ->money($currency)
                        ->sortable(),
                    TextColumn::make('amount_collected')
                        ->label('Partially settled')
                        ->money($currency)
                        ->sortable()
                        ->placeholder(__('—')),
                    TextColumn::make('late_fee_collected_amount')
                        ->label('Late fees settled')
                        ->money($currency)
                        ->sortable()
                        ->placeholder(__('—')),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state, Contribution $record): string => LateSettledArrearsTableStyling::contributionStatusLabel($record))
                        ->color(fn (string $state, Contribution $record): string => LateSettledArrearsTableStyling::contributionStatusColor($record))
                        ->tooltip(fn (Contribution $record): ?string => LateSettledArrearsTableStyling::contributionWasSettledLate($record)
                            ? LateSettledArrearsTableStyling::eligibilityHint()
                            : null),
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
                ->recordClasses(fn (Contribution $record): ?string => LateSettledArrearsTableStyling::contributionRecordClasses($record))
                ->recordActions(TableRecordActionGroups::wrap([]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('period', 'desc'),
            TableGrouping::contributions(includeMember: false)
        );
    }
}
