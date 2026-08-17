<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyFundOutRequests\Tables;

use App\Filament\Member\Support\MemberPendingRequestFilamentActions;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Setting;
use App\Support\OperationalRequestStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MyFundOutRequestsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            TableRecordActionGroups::apply(
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
                            ->formatStateUsing(fn (string $state): string => OperationalRequestStatus::label($state))
                            ->color(fn (string $state): string => OperationalRequestStatus::color($state)),
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
                            ->options(OperationalRequestStatus::options()),
                        DateColumnRangeFilter::make('created_at', __('Submitted')),
                    ])
                    ->defaultSort('created_at', 'desc')
                    ->toolbarActions(TableToolbar::bulkGroup([
                        MemberPendingRequestFilamentActions::cancelSelectedFundOuts(),
                        TableToolbar::refreshBulkAction(),
                    ])),
                [MemberPendingRequestFilamentActions::cancelFundOut()],
            ),
            TableGrouping::fundPostings(includeMember: false)
        );
    }
}
