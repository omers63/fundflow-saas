<?php

namespace App\Filament\Resources\Tenants\Tables;

use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Central\Tenant;
use App\Services\TenantDeletionService;
use App\Services\TenantProvisioningService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID / Subdomain')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Business name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Tenant $record): string => TenantResource::getUrl('view', ['record' => $record])),
                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_provisioned')
                    ->label('Provisioned')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggledHiddenByDefault(),
            ])
            ->filters([
                SelectFilter::make('plan_id')
                    ->label('Plan')
                    ->relationship('plan', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_provisioned')
                    ->label('Provisioned'),
                DateColumnRangeFilter::make('created_at', 'Created'),
            ])
            ->recordUrl(fn (Model $record): string => TenantResource::getUrl('view', ['record' => $record]))
            ->recordActions(TableRecordActionGroups::wrap([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn () => auth()->user()->hasRole('super_admin')),
                Action::make('provision')
                    ->label('Provision')
                    ->icon('heroicon-o-cpu-chip')
                    ->color('success')
                    ->requiresConfirmation()
                    ->hidden(fn (Tenant $record) => $record->is_provisioned)
                    ->visible(fn () => auth()->user()->hasRole('super_admin'))
                    ->action(function (Tenant $record, TenantProvisioningService $service) {
                        $service->provisionManual($record);
                        Notification::make()
                            ->title(__('Provisioning started'))
                            ->success()
                            ->send();
                    }),
                Action::make('purge')
                    ->label('Purge')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('This will permanently delete the tenant database, storage, and record. This action cannot be undone.'))
                    ->visible(fn () => auth()->user()->hasRole('super_admin'))
                    ->action(function (Tenant $record, TenantDeletionService $service) {
                        $service->deleteTenant($record);
                        Notification::make()
                            ->title(__('Tenant purge process started'))
                            ->warning()
                            ->send();
                    }),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('provisionSelected')
                        ->label(__('Provision'))
                        ->icon('heroicon-o-cpu-chip')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn () => auth()->user()->hasRole('super_admin'))
                        ->action(function (Collection $records, TenantProvisioningService $service) {
                            foreach ($records as $tenant) {
                                if (! $tenant->is_provisioned) {
                                    $service->provisionManual($tenant);
                                }
                            }
                            Notification::make()
                                ->title(__('Provisioning started for selected tenants'))
                                ->success()
                                ->send();
                        }),
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()->hasRole('super_admin')),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]);
    }
}
