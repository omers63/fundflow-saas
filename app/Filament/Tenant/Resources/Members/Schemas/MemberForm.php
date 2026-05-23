<?php

namespace App\Filament\Tenant\Resources\Members\Schemas;

use App\Models\Tenant\Member;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
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
                            ->required()
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
                                ->whereNull('parent_member_id')
                                ->when($record !== null, fn ($query) => $query->whereKeyNot($record->getKey()))
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->nullable()
                            ->helperText(__('Only fund administrators can link a member to a household parent. Applicants cannot choose a parent themselves.')),
                        TextInput::make('portal_password')
                            ->label(__('Portal password'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->visible(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(false)
                            ->helperText(__('Initial password for this member\'s own login account.')),
                    ]),
                Section::make(__('Portal & household'))
                    ->icon('heroicon-o-home')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('household_email')
                            ->email()
                            ->maxLength(255)
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('Set automatically from the parent household when this member is a dependent.')),
                        TextInput::make('portal_pin')
                            ->label(__('Portal PIN'))
                            ->password()
                            ->revealable()
                            ->maxLength(20)
                            ->helperText(__('Optional PIN for household profile picker.')),
                        Toggle::make('is_separated')
                            ->label(__('Separated household'))
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('Automatically enabled when this member\'s email differs from the household login email.')),
                        Toggle::make('direct_login_enabled')
                            ->label(__('Direct login enabled'))
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('Automatically enabled for dependents with their own email so they can sign in directly.')),
                    ]),
                Section::make(__('Historical migration'))
                    ->icon('heroicon-o-clock')
                    ->columnSpanFull()
                    ->columns(2)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        Select::make('migration_status')
                            ->label(__('Migration status'))
                            ->options(Member::migrationStatusOptions())
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder(__('Not started'))
                            ->helperText(__('Use the Migration cycles tab on this member to generate stubs and clear migration.')),
                        DatePicker::make('migration_cutoff_date')
                            ->label(__('Migration cutoff'))
                            ->disabled()
                            ->dehydrated(false),
                        Placeholder::make('opening_balances_posted_at')
                            ->label(__('Opening balances posted'))
                            ->content(fn (?Member $record): string => $record?->opening_balances_posted_at !== null
                                ? $record->opening_balances_posted_at->toDateTimeString()
                                : __('Not posted')),
                        Placeholder::make('partial_clearance_granted_at')
                            ->label(__('Partial clearance'))
                            ->content(fn (?Member $record): string => $record?->partial_clearance_granted_at !== null
                                ? $record->partial_clearance_granted_at->toDateTimeString()
                                : __('—')),
                    ]),
            ]);
    }
}
