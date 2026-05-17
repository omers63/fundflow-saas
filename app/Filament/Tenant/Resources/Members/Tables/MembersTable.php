<?php

namespace App\Filament\Tenant\Resources\Members\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MembersTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->columns([
                    MemberTableColumns::number()
                        ->searchable()
                        ->sortable(),
                    MemberTableColumns::name()
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('email')
                        ->searchable(),
                    TextColumn::make('phone')
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('monthly_contribution_amount')
                        ->money(fn(): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable(),
                    TextColumn::make('parent.name')
                        ->label('Parent')
                        ->placeholder(__('Independent')),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn(string $state): string => Member::statusOptions()[$state] ?? ucfirst($state))
                        ->color(fn(string $state): string => Member::statusBadgeColor($state)),
                    TextColumn::make('joined_at')
                        ->date()
                        ->sortable(),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options(Member::statusOptions()),
                    SelectFilter::make('parent_member_id')
                        ->label('Parent')
                        ->relationship('parent', 'name')
                        ->searchable()
                        ->preload(),
                    DateColumnRangeFilter::make('joined_at', 'Joined'),
                ])
                ->recordActions(TableRecordActionGroups::wrap([
                    EditAction::make(),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        DeleteBulkAction::make(),
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('name'),
            TableGrouping::members()
        );
    }
}
