<?php

namespace App\Filament\Resources\Members\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MemberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('family_id')->relationship('family', 'name')->required()->searchable(),
                Select::make('parent_member_id')->relationship('parent', 'full_name')->searchable()->preload(),
                TextInput::make('full_name')->required()->maxLength(255),
                TextInput::make('relation')->required()->default('self'),
                DatePicker::make('date_of_birth'),
                TextInput::make('national_id'),
                Select::make('status')->options([
                    'active' => 'Active',
                    'inactive' => 'Inactive',
                ])->default('active')->required(),
            ]);
    }
}
