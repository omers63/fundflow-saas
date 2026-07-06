<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\NotificationLog;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class MemberAlertHistoryTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return __('Alerts');
    }

    protected function getTableQuery(): Builder
    {
        $userId = auth('tenant')->id();

        if ($userId === null) {
            return NotificationLog::query()->whereRaw('0 = 1');
        }

        return NotificationLog::query()
            ->where('user_id', $userId)
            ->latest('sent_at');
    }

    public function table(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->heading(__('Alerts'))
                ->description(__('Past SMS, email, and in-app alerts sent to you. This list is read-only.'))
                ->columns([
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
                            default => $state ?? __('—'),
                        }),
                    TextColumn::make('subject')
                        ->label(__('Subject'))
                        ->searchable()
                        ->wrap()
                        ->limit(80),
                    TextColumn::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            'sent' => __('Sent'),
                            'failed' => __('Failed'),
                            'skipped' => __('Skipped'),
                            default => $state ?? __('—'),
                        })
                        ->color(fn (?string $state): string => match ($state) {
                            'sent' => 'success',
                            'failed' => 'danger',
                            'skipped' => 'gray',
                            default => 'gray',
                        }),
                    TextColumn::make('sent_at')
                        ->label(__('Sent'))
                        ->dateTime()
                        ->sortable(),
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
                    DateColumnRangeFilter::make('sent_at', __('Date')),
                ])
                ->defaultSort('sent_at', 'desc')
                ->emptyStateHeading(__('No alerts yet'))
                ->emptyStateDescription(__('When the fund sends you notifications, they will appear here.'))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ]),
            [
                Group::make('channel')
                    ->label(__('Channel'))
                    ->titlePrefixedWithLabel(false)
                    ->getTitleFromRecordUsing(fn (NotificationLog $record): string => match ($record->channel) {
                        'mail' => __('Email'),
                        'database' => __('In-app'),
                        'twilio' => __('SMS'),
                        'whatsapp' => __('WhatsApp'),
                        default => $record->channel ?? __('—'),
                    }),
            ],
        );
    }
}
