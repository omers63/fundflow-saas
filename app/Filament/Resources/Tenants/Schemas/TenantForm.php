<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\Models\Tenant;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tenant')
                    ->schema([
                        TextInput::make('id')
                            ->required()
                            ->alphaDash()
                            ->maxLength(64)
                            ->unique(Tenant::class, 'id', ignoreRecord: true)
                            ->helperText('Unique tenant key, e.g. al-hassan'),
                        TextInput::make('name')->required(),
                        TextInput::make('slug')
                            ->required()
                            ->alphaDash()
                            ->unique(Tenant::class, 'slug', ignoreRecord: true),
                        TextInput::make('tenancy_db_name')
                            ->label('Tenant DB name')
                            ->helperText('Optional. Auto-generated when empty.'),
                    ])->columns(2),
                Section::make('Domain')
                    ->schema([
                        TextInput::make('primary_domain')
                            ->label('Primary domain')
                            ->required(fn(string $operation): bool => $operation === 'create')
                            ->helperText('Example: al-hassan.localhost'),
                    ]),
                Section::make('Provision admin user')
                    ->schema([
                        TextInput::make('admin_name')->required(fn(string $operation): bool => $operation === 'create'),
                        TextInput::make('admin_email')->email()->required(fn(string $operation): bool => $operation === 'create'),
                        TextInput::make('admin_password')->password()->required(fn(string $operation): bool => $operation === 'create')->minLength(8),
                    ])->columns(2),
            ]);
    }
}
