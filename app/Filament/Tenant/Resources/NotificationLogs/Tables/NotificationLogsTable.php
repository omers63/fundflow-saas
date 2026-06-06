<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\NotificationLogs\Tables;

use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\NotificationLog;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotificationLogsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply($table
            ->query(NotificationLog::query()->with('user')->latest('sent_at'))
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
                Filter::make('sent_at')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, mixed $from): Builder => $q->whereDate('sent_at', '>=', $from))
                            ->when($data['until'] ?? null, fn (Builder $q, mixed $until): Builder => $q->whereDate('sent_at', '<=', $until));
                    }),
                TrashedFilter::make(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->recordActions(TableRecordActionGroups::wrap([
                ViewAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::notificationLogs());
    }
}
