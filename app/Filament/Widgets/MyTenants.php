<?php

namespace App\Filament\Widgets;

use App\Models\Central\Tenant;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class MyTenants extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()->hasRole('user');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Tenant::query()->where('central_user_id', auth()->id()))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Tenant Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('id')
                    ->label('Subdomain')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => $state.'.'.config('tenancy.central_domain')),
                Tables\Columns\BadgeColumn::make('is_provisioned')
                    ->label('Status')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Provisioned' : 'Pending')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
