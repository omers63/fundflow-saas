<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\PortalAccessLogs\Tables;

use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Support\ViewPortalAccessLogAction;
use App\Models\Tenant\PortalAccessLog;
use App\Support\Lang;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PortalAccessLogsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply($table
            ->query(PortalAccessLog::query()->with(['member', 'user']))
            ->columns([
                TextColumn::make('member_name')
                    ->label(__('Member name'))
                    ->searchable()
                    ->sortable()
                    ->placeholder(__('—'))
                    ->description(fn (PortalAccessLog $record): ?string => $record->member?->member_number),
                TextColumn::make('user.email')
                    ->label(__('Login email'))
                    ->searchable()
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('panel')
                    ->label(__('Portal'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        PortalAccessLog::PANEL_MEMBER => 'info',
                        PortalAccessLog::PANEL_ADMIN => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        PortalAccessLog::PANEL_MEMBER => __('Member portal'),
                        PortalAccessLog::PANEL_ADMIN => __('Admin portal'),
                        default => $state ?? '—',
                    }),
                TextColumn::make('ip_address')
                    ->label(__('IP address'))
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('accessed_at')
                    ->label(__('Accessed at'))
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                TextColumn::make('user_agent')
                    ->label(__('Device'))
                    ->limit(40)
                    ->tooltip(fn (PortalAccessLog $record): ?string => $record->user_agent)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('panel')
                    ->label(__('Portal'))
                    ->options(Lang::transOptions([
                        PortalAccessLog::PANEL_MEMBER => 'Member portal',
                        PortalAccessLog::PANEL_ADMIN => 'Admin portal',
                    ])),
                Filter::make('accessed_at')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, mixed $from): Builder => $q->whereDate('accessed_at', '>=', $from))
                            ->when($data['until'] ?? null, fn (Builder $q, mixed $until): Builder => $q->whereDate('accessed_at', '<=', $until));
                    }),
                TrashedFilter::make(),
            ])
            ->defaultSort('accessed_at', 'desc')
            ->recordActions(TableRecordActionGroups::wrap([
                ViewPortalAccessLogAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => auth('tenant')->user()?->is_admin === true)
                        ->modalHeading(__('Delete access log entries'))
                        ->modalDescription(__('Soft-deletes the selected access log rows.')),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::portalAccessLogs());
    }
}
