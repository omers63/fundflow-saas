<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MonthlyStatements\Schemas;

use App\Filament\Support\MemberSelect;
use App\Models\Tenant\Setting;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MonthlyStatementForm
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema
            ->columns(2)
            ->components([
                MemberSelect::configure(
                    Select::make('member_id')->label(__('Member')),
                    activeOnly: false,
                )->required(),
                TextInput::make('period')
                    ->label(__('Period (YYYY-MM)'))
                    ->placeholder('2026-05')
                    ->required()
                    ->regex('/^\d{4}-\d{2}$/'),
                TextInput::make('opening_balance')
                    ->label(__('Opening fund balance'))
                    ->numeric()
                    ->prefix($currency)
                    ->helperText(__('Member fund ledger balance immediately before the period starts.'))
                    ->required(),
                TextInput::make('total_contributions')
                    ->numeric()
                    ->prefix($currency)
                    ->required(),
                TextInput::make('total_repayments')
                    ->numeric()
                    ->prefix($currency)
                    ->required(),
                TextInput::make('closing_balance')
                    ->label(__('Closing fund balance'))
                    ->numeric()
                    ->prefix($currency)
                    ->helperText(__('Member fund ledger balance at the end of the period.'))
                    ->required(),
            ]);
    }
}
