<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\Models\Central\Plan;
use App\Models\Central\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Tenant Information'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('id')
                            ->label('Tenant ID / subdomain')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->disabled(fn ($record) => $record !== null)
                            ->placeholder(__('e.g. acme')),
                        TextInput::make('name')
                            ->label('Business name')
                            ->required(),
                        Select::make('central_user_id')
                            ->label('Owner')
                            ->options(User::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Select::make('plan_id')
                            ->label('Subscription plan')
                            ->options(Plan::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ]),

                Section::make(__('Provisioning Status'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_provisioned')
                            ->label('Is provisioned')
                            ->disabled()
                            ->dehydrated(false),
                        DateTimePicker::make('provisioned_at')
                            ->label('Provisioned at')
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ]);
    }
}
