<?php

namespace App\Filament\Member\Resources\Members\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MemberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('full_name')->required(),
                TextInput::make('relation')->required(),
                DatePicker::make('date_of_birth'),
                TextInput::make('national_id'),
            ]);
    }
}
