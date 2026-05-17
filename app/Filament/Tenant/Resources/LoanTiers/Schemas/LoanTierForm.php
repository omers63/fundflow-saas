<?php

namespace App\Filament\Tenant\Resources\LoanTiers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LoanTierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('tier_number')
                    ->numeric()
                    ->required()
                    ->minValue(1),
                TextInput::make('label')
                    ->maxLength(100),
                TextInput::make('min_amount')
                    ->numeric()
                    ->required()
                    ->minValue(0),
                TextInput::make('max_amount')
                    ->numeric()
                    ->required()
                    ->minValue(0),
                TextInput::make('min_monthly_installment')
                    ->numeric()
                    ->required()
                    ->minValue(1),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
