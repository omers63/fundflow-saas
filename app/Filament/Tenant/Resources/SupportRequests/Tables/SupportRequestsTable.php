<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SupportRequests\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\SupportRequest;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
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
                    SelectFilter::make('category')
                        ->options(SupportRequest::categoryOptions()),
                    DateColumnRangeFilter::make('created_at', 'Submitted'),
                ])
                ->defaultSort('created_at', 'desc')
                ->recordActions(TableRecordActionGroups::wrap([
                    Action::make('viewMessage')
                        ->label(__('View'))
                        ->icon('heroicon-o-eye')
                        ->color('gray')
                        ->modalHeading(fn (SupportRequest $record): string => __('Support request #:id', ['id' => $record->id]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Close'))
                        ->modalContent(fn (SupportRequest $record): View => view(
                            'filament.tenant.components.support-request-detail',
                            ['record' => $record],
                        )),
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
