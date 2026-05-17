<?php

namespace App\Filament\Tenant\Resources\FundTiers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FundTierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('tier_number')
                    ->numeric()
                    ->required()
                    ->helperText(__('Use 0 for emergency tier.')),
                TextInput::make('label')
                    ->maxLength(100),
                Select::make('loan_tier_id')
                    ->label(__('Linked loan tier'))
                    ->relationship('loanTier', 'label')
                    ->searchable()
                    ->preload()
                    ->nullable(),
                TextInput::make('percentage')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%'),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
