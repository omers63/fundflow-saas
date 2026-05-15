<?php

namespace App\Filament\Tenant\Resources\Members\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Tenant\Resources\Members\MemberResource;
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
        return $table
            ->columns([
                TextColumn::make('member_number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Member $record): string => MemberResource::getUrl('view', ['record' => $record])),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('phone')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('monthly_contribution_amount')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label('Parent')
                    ->placeholder(__('Independent')),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'warning',
                        'withdrawn' => 'danger',
                    }),
                TextColumn::make('joined_at')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'suspended' => 'Suspended',
                        'withdrawn' => 'Withdrawn',
                    ]),
                SelectFilter::make('parent_member_id')
                    ->label('Parent')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload(),
                DateColumnRangeFilter::make('joined_at', 'Joined'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}
