<?php

namespace App\Filament\Tenant\Resources\Contributions\Schemas;

use App\Models\Tenant\Member;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContributionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Contribution Details'))
                    ->columns(2)
                    ->schema([
                        Select::make('member_id')
                            ->label('Member')
                            ->options(Member::active()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(fn ($state, callable $set) => $set(
                                'amount',
                                Member::find($state)?->monthly_contribution_amount ?? 0
                            )),
                        DatePicker::make('period')
                            ->required()
                            ->default(now()->startOfMonth()),
                        TextInput::make('amount')
                            ->numeric()
                            ->prefix('$')
                            ->required()
                            ->minValue(0),
                    ]),
            ]);
    }
}
