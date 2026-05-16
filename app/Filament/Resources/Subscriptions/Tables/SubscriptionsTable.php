<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        'cancelled', 'expired' => 'danger',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('starts_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggledHiddenByDefault(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'cancelled' => 'Cancelled',
                        'expired' => 'Expired',
                        'pending_upgrade' => 'Pending upgrade',
                        'pending_extension' => 'Pending extension',
                    ]),
                SelectFilter::make('plan_id')
                    ->label('Plan')
                    ->relationship('plan', 'name')
                    ->searchable()
                    ->preload(),
                DateColumnRangeFilter::make('starts_at', 'Starts'),
                DateColumnRangeFilter::make('ends_at', 'Ends'),
                DateColumnRangeFilter::make('created_at', 'Created'),
            ])
            ->recordUrl(fn (Model $record): string => SubscriptionResource::getUrl('view', ['record' => $record]))
            ->recordActions(TableRecordActionGroups::wrap([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Model $record) => auth()->user()->hasRole('super_admin') || $record->tenant->central_user_id === auth()->id()),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()->hasRole('super_admin')),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]);
    }
}
