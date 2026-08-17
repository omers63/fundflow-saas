<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyCashTransfers\Tables;

use App\Filament\Member\Support\MemberPendingRequestFilamentActions;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Models\Tenant\Setting;
use App\Support\OperationalRequestStatus;
use App\Support\Tenant\CurrentMember;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MyCashTransfersTable
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
                        TextColumn::make('direction')
                            ->label(__('Direction'))
                            ->state(function (MemberCashTransferRequest $record): string {
                                $me = CurrentMember::id();

                                return (int) $record->from_member_id === (int) $me
                                    ? __('Sent')
                                    : __('Received');
                            }),
                        TextColumn::make('counterpart')
                            ->label(__('Other member'))
                            ->state(function (MemberCashTransferRequest $record): string {
                                $me = CurrentMember::id();

                                if ((int) $record->from_member_id === (int) $me) {
                                    return $record->toMember?->name ?? $record->recipient_name;
                                }

                                return $record->fromMember?->name ?? __('Member');
                            })
                            ->wrap(),
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
                        MemberPendingRequestFilamentActions::cancelSelectedCashTransfers(),
                        TableToolbar::refreshBulkAction(),
                    ])),
                [MemberPendingRequestFilamentActions::cancelCashTransfer()],
            ),
            TableGrouping::fundPostings(includeMember: false),
        );
    }
}
