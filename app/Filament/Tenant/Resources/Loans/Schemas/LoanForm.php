<?php

namespace App\Filament\Tenant\Resources\Loans\Schemas;

use App\Models\Tenant\Member;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LoanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Loan Application'))
                    ->columns(2)
                    ->schema([
                        Select::make('member_id')
                            ->label('Member')
                            ->options(Member::active()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        TextInput::make('amount')
                            ->numeric()
                            ->prefix('$')
                            ->required()
                            ->minValue(1),
                        TextInput::make('interest_rate')
                            ->numeric()
                            ->suffix('%')
                            ->required()
                            ->default(10)
                            ->minValue(0)
                            ->maxValue(100),
                        TextInput::make('term_months')
                            ->label('Term (months)')
                            ->numeric()
                            ->required()
                            ->default(12)
                            ->minValue(1)
                            ->maxValue(60),
                    ]),
            ]);
    }
}
