<?php

namespace App\Filament\Tenant\Resources\Members\Schemas;

use App\Models\Tenant\Member;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MemberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Membership'))
                    ->icon('heroicon-o-identification')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('member_number')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->default(fn (): string => Member::generateMemberNumber())
                            ->disabled()
                            ->dehydrated()
                            ->helperText(__('Format is configured in fund settings.')),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                        Select::make('monthly_contribution_amount')
                            ->label(__('Monthly contribution'))
                            ->options(Member::contributionAmountOptions())
                            ->default(500)
                            ->required()
                            ->helperText(__('Multiples of :step, from :min to :max.', [
                                'step' => 500,
                                'min' => 500,
                                'max' => 3000,
                            ])),
                        DatePicker::make('joined_at')
                            ->required()
                            ->default(now()),
                        Select::make('status')
                            ->options(Member::statusOptions())
                            ->default('active')
                            ->required(),
                        Select::make('parent_member_id')
                            ->label(__('Parent member'))
                            ->options(fn (?Member $record) => Member::query()
                                ->where('id', '!=', $record?->id)
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->nullable()
                            ->helperText(__('If set, this member is a dependent of the selected parent.')),
                    ]),
                Section::make(__('Portal & household'))
                    ->icon('heroicon-o-home')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('household_email')
                            ->email()
                            ->maxLength(255)
                            ->helperText(__('Shared login email for family members.')),
                        TextInput::make('portal_pin')
                            ->label(__('Portal PIN'))
                            ->password()
                            ->revealable()
                            ->maxLength(20)
                            ->helperText(__('Optional PIN for household profile picker.')),
                        Toggle::make('is_separated')
                            ->label(__('Separated household'))
                            ->helperText(__('Dependent uses a different email and can log in directly.')),
                        Toggle::make('direct_login_enabled')
                            ->label(__('Direct login enabled'))
                            ->visible(fn (callable $get): bool => (bool) $get('is_separated')),
                    ]),
            ]);
    }
}
