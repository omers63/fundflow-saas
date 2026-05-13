<?php

namespace App\Filament\Resources\Plans\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General Information')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (string $operation, $state, $set) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),
                        TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true),
                        TextInput::make('price')
                            ->required()
                            ->numeric()
                            ->default(0.0),
                        TextInput::make('currency')
                            ->required()
                            ->default('IDR'),
                        TextInput::make('billing_cycle')
                            ->required()
                            ->default('monthly'),
                        TextInput::make('duration_months')
                            ->required()
                            ->numeric()
                            ->default(1),
                        Textarea::make('description')
                            ->columnSpanFull(),
                    ]),

                Section::make('Configuration')
                    ->description('Flexible limits and features for this plan')
                    ->schema([
                        KeyValue::make('data')
                            ->keyLabel('Feature/Limit Name')
                            ->valueLabel('Value')
                            ->helperText('Add custom data like max_users, storage_limit, etc.'),
                    ]),

                Section::make('Status')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->required(),
                        Toggle::make('is_custom')
                            ->label('Custom Plan')
                            ->default(false)
                            ->required(),
                        Toggle::make('support_custom_domain')
                            ->label('Support Custom Domain')
                            ->default(false)
                            ->required(),
                    ]),
            ]);
    }
}
