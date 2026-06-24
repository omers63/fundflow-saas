<?php

namespace App\Filament\Tenant\Resources\MembershipApplications\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MembershipApplicationFilamentActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Models\Tenant\MembershipApplication;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Component;

class MembershipApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return TableRecordActionGroups::apply(
            TableGrouping::apply(
                $table
                    ->columns([
                        TextColumn::make('name')
                            ->searchable()
                            ->sortable(),
                        TextColumn::make('email')
                            ->searchable(),
                        TextColumn::make('parentApplication.name')
                            ->label(__('Household parent'))
                            ->placeholder('—')
                            ->toggleable(),
                        TextColumn::make('phone'),
                        TextColumn::make('application_type')
                            ->label(__('Type'))
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                        TextColumn::make('message')
                            ->limit(40),
                        TextColumn::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'approved' => 'success',
                                'rejected' => 'danger',
                                default => 'gray',
                            }),
                        TextColumn::make('created_at')
                            ->dateTime()
                            ->sortable(),
                    ])
                    ->filters([
                        SelectFilter::make('status')
                            ->options([
                                'pending' => __('Pending'),
                                'approved' => __('Approved'),
                                'rejected' => __('Rejected'),
                            ]),
                        DateColumnRangeFilter::make('created_at', 'Submitted'),
                    ])
                    ->toolbarActions([
                        BulkActionGroup::make([
                            MembershipApplicationFilamentActions::approveBulk(),
                            MembershipApplicationFilamentActions::rejectBulk(),
                            DeleteBulkAction::make()
                                ->after(fn (Component $livewire) => MembershipApplicationResource::dispatchInsightsRefresh($livewire)),
                            TableToolbar::refreshBulkAction(),
                        ]),
                    ])
                    ->defaultSort('created_at', 'desc'),
                TableGrouping::membershipApplications(),
            ),
            [],
            fn (MembershipApplication $record): string => MembershipApplicationResource::getUrl('edit', ['record' => $record]),
        );
    }
}
