<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MonthlyStatements\Schemas;

use App\Models\Tenant\Member;
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
                Select::make('member_id')
                    ->label(__('Member'))
                    ->options(fn (): array => Member::query()
                        ->orderBy('member_number')
                        ->get()
                        ->mapWithKeys(fn (Member $member): array => [
                            $member->id => "{$member->member_number} — {$member->name}",
                        ])
                        ->all())
                    ->searchable()
                    ->required(),
                TextInput::make('period')
                    ->label(__('Period (YYYY-MM)'))
                    ->placeholder('2026-05')
                    ->required()
                    ->regex('/^\d{4}-\d{2}$/'),
                TextInput::make('opening_balance')
                    ->numeric()
                    ->prefix($currency)
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
                    ->numeric()
                    ->prefix($currency)
                    ->required(),
            ]);
    }
}
