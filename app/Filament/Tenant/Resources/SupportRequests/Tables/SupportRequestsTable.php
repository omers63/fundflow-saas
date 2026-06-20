<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SupportRequests\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Support\ManageSupportRequestAction;
use App\Filament\Tenant\Support\ViewSupportRequestAction;
use App\Models\Tenant\SupportRequest;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SupportRequestsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->with([
                        'user',
                        'member',
                    ])
                )
                ->columns([
                    TextColumn::make('id')
                        ->label(__('ID'))
                        ->sortable(),
                    TextColumn::make('member.member_number')
                        ->label(__('Member #'))
                        ->placeholder(__('—'))
                        ->url(fn (SupportRequest $record): ?string => $record->member
                            ? MemberTableColumns::memberRecordEditUrl($record->member)
                            : null)
                        ->sortable(),
                    TextColumn::make('user.name')
                        ->label(__('Submitted by'))
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('category')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => SupportRequest::categoryLabel($state)),
                    TextColumn::make('subject')
                        ->searchable()
                        ->wrap(),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => SupportRequest::statusOptions()[$state] ?? $state)
                        ->color(fn (string $state): string => SupportRequest::statusColor($state)),
                    TextColumn::make('sla')
                        ->label(__('SLA'))
                        ->badge()
                        ->state(fn (SupportRequest $record): string => trans_choice(':count day|:count days', $record->daysOpen(), ['count' => $record->daysOpen()]))
                        ->color(fn (SupportRequest $record): string => SupportRequest::slaColor($record->daysOpen())),
                    TextColumn::make('escalated_at')
                        ->label(__('Escalated'))
                        ->dateTime()
                        ->placeholder(__('—')),
                    TextColumn::make('message')
                        ->limit(60)
                        ->tooltip(fn (SupportRequest $record): string => $record->message)
                        ->wrap(),
                    TextColumn::make('created_at')
                        ->label(__('Submitted'))
                        ->dateTime()
                        ->sortable(),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options(SupportRequest::statusOptions()),
                    SelectFilter::make('category')
                        ->options(SupportRequest::categoryOptions()),
                    DateColumnRangeFilter::make('created_at', 'Submitted'),
                ])
                ->defaultSort('created_at', 'desc')
                ->recordActions(TableRecordActionGroups::wrap([
                    ManageSupportRequestAction::make(),
                    ViewSupportRequestAction::make(),
                    DeleteAction::make(),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        DeleteBulkAction::make(),
                        TableToolbar::refreshBulkAction(),
                    ]),
                ]),
            [
                Group::make('category')
                    ->label(__('Category'))
                    ->titlePrefixedWithLabel(false)
                    ->getTitleFromRecordUsing(fn (SupportRequest $record): string => SupportRequest::categoryLabel($record->category)),
            ],
        );
    }
}
