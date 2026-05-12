<?php

namespace App\Filament\Resources\Enrollments\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EnrollmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('family_id')->relationship('family', 'name')->required()->searchable(),
                Select::make('member_id')->relationship('member', 'full_name')->searchable(),
                TextInput::make('applicant_name')->required()->maxLength(255),
                TextInput::make('email')->email()->required(),
                TextInput::make('phone'),
                Textarea::make('notes')->columnSpanFull(),
                Select::make('status')->options([
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ])->default('pending')->required(),
            ]);
    }
}
