<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyMessages\Tables;

use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\DirectMessage;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MyMessagesTable
{
    public static function configure(Table $table): Table
    {
        $memberUserId = auth('tenant')->id();

        return TableGrouping::apply($table
            ->columns([
                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? $state : __('No subject'))
                    ->searchable(),
                TextColumn::make('body')
                    ->label(__('Preview'))
                    ->limit(80),
                TextColumn::make('sender.name')
                    ->label(__('From'))
                    ->placeholder(__('—')),
                TextColumn::make('created_at')
                    ->label(__('Last activity'))
                    ->formatStateUsing(
                        fn ($state): string => $state ? Carbon::parse($state)->locale(app()->getLocale())->translatedFormat('d M Y H:i') : __('—')
                    )
                    ->sortable(),
                TextColumn::make('read_at')
                    ->label(__('Status'))
                    ->badge()
                    ->getStateUsing(function (DirectMessage $record) use ($memberUserId): string {
                        if ((int) $record->to_user_id === $memberUserId && $record->read_at === null) {
                            return __('Unread');
                        }

                        return __('Read');
                    })
                    ->color(fn (string $state): string => $state === __('Unread') ? 'warning' : 'success'),
            ])
            ->filters([
                TernaryFilter::make('unread')
                    ->label(__('Unread only'))
                    ->queries(
                        true: fn ($query) => $query
                            ->where('to_user_id', $memberUserId)
                            ->whereNull('read_at'),
                        false: fn ($query) => $query,
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordUrl(fn (Model $record): string => MyMessageResource::getUrl('view', ['record' => $record]))
            ->recordActions(TableRecordActionGroups::wrap([
                ViewAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->defaultSort('created_at', 'desc'), TableGrouping::directMessages());
    }
}
