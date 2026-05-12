<?php

namespace App\Filament\Resources\SystemSettings\Schemas;

use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SystemSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Application')
                    ->schema([
                        TextInput::make('app_name')->required(),
                        TextInput::make('support_email')->email(),
                    ])->columns(2),
                Section::make('Public Experience')
                    ->schema([
                        TextInput::make('public_hero_title')->required(),
                        Textarea::make('public_hero_subtitle')->rows(3)->columnSpanFull(),
                        TextInput::make('public_primary_color')->required()->helperText('Hex color, e.g. #4f46e5'),
                        TextInput::make('public_secondary_color')->required()->helperText('Hex color, e.g. #0ea5e9'),
                    ])->columns(2),
                Section::make('Panel Theming')
                    ->schema([
                        TextInput::make('admin_primary_color')->required(),
                        TextInput::make('member_primary_color')->required(),
                    ])->columns(2),
                Section::make('Maintenance Mode')
                    ->schema([
                        Toggle::make('maintenance_enabled')
                            ->label('Enable maintenance mode'),
                        Textarea::make('maintenance_message')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
