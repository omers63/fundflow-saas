<?php

namespace App\Filament\Member\Resources\MyFundPostings\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Support\ViewActions\ViewFundPostingAction;
use App\Models\Tenant\Setting;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MyFundPostingsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            TableRecordActionGroups::apply(
                $table
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
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'pending' => __('Pending'),
                                'accepted' => __('Accepted'),
                                'rejected' => __('Rejected'),
                                default => ucfirst($state),
                            })
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'accepted' => 'success',
                                'rejected' => 'danger',
                            }),
                        TextColumn::make('admin_remarks')
                            ->label(__('Admin remarks'))
                            ->placeholder(__('—'))
                            ->limit(40),
                        TextColumn::make('created_at')
                            ->label(__('Submitted'))
                            ->dateTime()
                            ->sortable(),
                    ])
                    ->filters([
                        SelectFilter::make('status')
                            ->options([
                                'pending' => __('Pending'),
                                'accepted' => __('Accepted'),
                                'rejected' => __('Rejected'),
                            ]),
                        DateColumnRangeFilter::make('posting_date', __('Posting date')),
                        DateColumnRangeFilter::make('created_at', __('Submitted')),
                    ])
                    ->defaultSort('created_at', 'desc')
                    ->toolbarActions(TableToolbar::bulkGroup([
                        TableToolbar::refreshBulkAction(),
                    ])),
                [ViewFundPostingAction::makeForMemberPortal()],
            ),
            TableGrouping::fundPostings(includeMember: false),
        );
    }
}
