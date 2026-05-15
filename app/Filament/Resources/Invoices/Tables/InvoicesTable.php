<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Models\Central\Invoice;
use App\Services\TenantProvisioningService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money(fn ($record) => $record->currency)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'pending' => 'warning',
                        'failed', 'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('paid_at')
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
                        'pending' => 'Pending',
                        'paid' => 'Paid',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                    ]),
                SelectFilter::make('tenant_id')
                    ->label('Tenant')
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('plan_id')
                    ->label('Plan')
                    ->relationship('plan', 'name')
                    ->searchable()
                    ->preload(),
                DateColumnRangeFilter::make('paid_at', 'Paid on'),
                DateColumnRangeFilter::make('created_at', 'Created'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('markAsPaid')
                    ->label('Mark as Paid')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Invoice $record) => $record->status === 'pending' && auth()->user()->hasRole('super_admin'))
                    ->action(function (Invoice $record, TenantProvisioningService $service) {
                        $record->update([
                            'status' => 'paid',
                            'paid_at' => now(),
                            'payment_method' => 'manual_admin',
                        ]);

                        $service->provisionTenant($record);

                        Notification::make()
                            ->title(__('Invoice marked as paid'))
                            ->body(__('Tenant provisioning has been started.'))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
