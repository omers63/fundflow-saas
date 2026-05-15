<?php

namespace App\Filament\Tenant\Resources\Members\Schemas;

use App\Models\Tenant\Member;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MemberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Member Information'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('member_number')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->default(fn () => 'MEM-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT)),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                    ]),

                Section::make(__('Membership Details'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('monthly_contribution_amount')
                            ->numeric()
                            ->prefix('$')
                            ->required()
                            ->minValue(0),
                        DatePicker::make('joined_at')
                            ->required()
                            ->default(now()),
                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'suspended' => 'Suspended',
                                'withdrawn' => 'Withdrawn',
                            ])
                            ->default('active')
                            ->required(),
                        Select::make('parent_member_id')
                            ->label('Parent member')
                            ->options(fn (?Member $record) => Member::where('id', '!=', $record?->id)
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->nullable()
                            ->helperText(__('If set, this member is a dependent of the selected parent.')),
                    ]),
            ]);
    }
}
