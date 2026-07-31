<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\NotificationLogs\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Support\ViewNotificationLogAction;
use App\Models\Tenant\NotificationLog;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class NotificationLogsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply($table
            ->query(NotificationLog::query()->with('user'))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('Recipient'))
                    ->searchable()
                    ->placeholder(__('—'))
                    ->sortable(),
                TextColumn::make('user.email')
                    ->label(__('Email'))
                    ->searchable()
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('channel')
                    ->label(__('Channel'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'mail' => 'primary',
                        'database' => 'info',
                        'twilio' => 'success',
                        'whatsapp' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'mail' => __('Email'),
                        'database' => __('In-app'),
                        'twilio' => __('SMS'),
                        'whatsapp' => __('WhatsApp'),
                        default => $state ?? '—',
                    }),
                TextColumn::make('subject')
                    ->searchable()
                    ->limit(60)
                    ->tooltip(fn (NotificationLog $record): ?string => $record->subject),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'skipped' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('sent_at')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Logged at'))
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->options([
                        'mail' => __('Email'),
                        'database' => __('In-app'),
                        'twilio' => __('SMS'),
                        'whatsapp' => __('WhatsApp'),
                    ]),
                SelectFilter::make('status')
                    ->options([
                        'sent' => __('Sent'),
                        'failed' => __('Failed'),
                        'skipped' => __('Skipped'),
                    ]),
                DateColumnRangeFilter::make('sent_at', __('Sent')),
                TrashedFilter::make(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->recordActions(TableRecordActionGroups::wrap([
                ViewNotificationLogAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => auth('tenant')->user()?->is_admin === true)
                        ->modalHeading(__('Delete notification logs'))
                        ->modalDescription(__('Soft-deletes the selected delivery log rows.')),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::notificationLogs());
    }
}
