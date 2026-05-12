<?php

namespace App\Filament\Member\Resources\Enrollments\Schemas;

use Filament\Forms\Components\Hidden;
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
                Hidden::make('family_id')->default(fn() => auth()->user()?->family_id),
                TextInput::make('applicant_name')->required(),
                TextInput::make('email')->email()->required(),
                TextInput::make('phone'),
                Textarea::make('notes'),
                Select::make('status')->options([
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ])->default('pending')->required(),
            ]);
    }
}
