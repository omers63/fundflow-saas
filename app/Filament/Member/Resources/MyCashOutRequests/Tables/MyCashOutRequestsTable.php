<?php

namespace App\Filament\Member\Resources\MyCashOutRequests\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Setting;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MyCashOutRequestsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->columns([
                    TextColumn::make('id')
                        ->label(__('Request #'))
                        ->sortable(),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable(),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'pending' => __('Pending'),
                            'accepted' => __('Accepted'),
                            'rejected' => __('Rejected'),
                            default => ucfirst($state),
                        })
                        ->color(fn (string $state): string => match ($state) {
                            'pending' => 'warning',
                            'accepted' => 'success',
                            'rejected' => 'danger',
                        }),
                    TextColumn::make('notes')
                        ->placeholder(__('—'))
                        ->limit(40),
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
                    DateColumnRangeFilter::make('created_at', __('Submitted')),
                ])
                ->defaultSort('created_at', 'desc')
                ->toolbarActions(TableToolbar::bulkGroup([
                    TableToolbar::refreshBulkAction(),
                ])),
            TableGrouping::fundPostings(includeMember: false)
        );
    }
}
