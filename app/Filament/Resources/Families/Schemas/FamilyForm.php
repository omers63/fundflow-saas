<?php

namespace App\Filament\Resources\Families\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class FamilyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('slug')->required()->unique(ignoreRecord: true),
                TextInput::make('family_code')->required()->unique(ignoreRecord: true),
                Select::make('subscription_plan')->options([
                    'free' => 'Free',
                    'starter' => 'Starter',
                    'pro' => 'Pro',
                ])->default('free')->required(),
                Select::make('subscription_status')->options([
                    'trial' => 'Trial',
                    'active' => 'Active',
                    'past_due' => 'Past due',
                    'canceled' => 'Canceled',
                ])->default('trial')->required(),
            ]);
    }
}
