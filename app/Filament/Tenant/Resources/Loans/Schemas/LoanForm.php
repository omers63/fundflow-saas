<?php

namespace App\Filament\Tenant\Resources\Loans\Schemas;

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LoanForm
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema
            ->components([
                Section::make(__('Loan application'))
                    ->columns(2)
                    ->schema([
                        Select::make('member_id')
                            ->label(__('Member'))
                            ->options(Member::active()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->disabled(fn (?string $operation): bool => $operation === 'edit'),
                        TextInput::make('amount_requested')
                            ->label(__('Amount requested'))
                            ->numeric()
                            ->prefix($currency)
                            ->required()
                            ->minValue(1),
                        Select::make('guarantor_member_id')
                            ->label(__('Guarantor'))
                            ->options(Member::active()->pluck('name', 'id'))
                            ->searchable()
                            ->nullable(),
                        Toggle::make('is_emergency')
                            ->label(__('Emergency loan')),
                        Toggle::make('has_grace_cycle')
                            ->label(__('Grace cycle'))
                            ->default(true),
                        Textarea::make('purpose')
                            ->label(__('Purpose'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
