<?php

namespace App\Filament\Tenant\Resources\Members\Schemas;

use App\Filament\Support\MemberSelect;
use App\Filament\Support\MemberSelectOptions;
use App\Models\Tenant\Member;
use App\Services\MemberMonthlyAllocationService;
use App\Support\BusinessDay;
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
                self::detailSection(__('Membership'), __('Core profile and contribution settings.'))
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
                            ->disabled(fn (?Member $record, MemberMonthlyAllocationService $allocations): bool => $record !== null
                                && ! $allocations->canSelfChangeMonthlyContribution($record))
                            ->dehydrated(fn (?Member $record, MemberMonthlyAllocationService $allocations): bool => $record === null
                                || $allocations->canSelfChangeMonthlyContribution($record))
                            ->helperText(function (?Member $record, MemberMonthlyAllocationService $allocations): string {
                                if ($record !== null && ! $allocations->canSelfChangeMonthlyContribution($record)) {
                                    return $allocations->allocationChangeBlockedMessage($record);
                                }

                                return __('Multiples of :step, from :min to :max.', [
                                    'step' => 500,
                                    'min' => 500,
                                    'max' => 3000,
                                ]);
                            }),
                        DatePicker::make('joined_at')
                            ->required()
                            ->default(BusinessDay::now()),
                        Select::make('status')
                            ->options(Member::statusOptions())
                            ->default('active')
                            ->disabled()
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->helperText(__('Use Membership actions to change status — not this field.')),
                        Placeholder::make('status_reason_display')
                            ->label(__('Status note'))
                            ->content(fn (?Member $record): string => filled($record?->status_reason)
                                ? (string) $record->status_reason
                                : '—')
                            ->visible(fn (?Member $record): bool => $record !== null && filled($record->status_reason)),
                        MemberSelect::configure(
                            Select::make('parent_member_id')
                                ->label(__('Parent member'))
                                ->nullable()
                                ->helperText(__('Only fund administrators can link a member to a household parent. Dependents must use the parent\'s household email.')),
                            activeOnly: false,
                        )
                            ->options(fn (?Member $record): array => MemberSelectOptions::options(
                                query: Member::query()
                                    ->whereNull('parent_member_id')
                                    ->when($record !== null, fn ($query) => $query->whereKeyNot($record->getKey())),
                                limit: 75,
                            ))
                            ->getSearchResultsUsing(fn (string $search, ?Member $record): array => MemberSelectOptions::search(
                                $search,
                                query: Member::query()
                                    ->whereNull('parent_member_id')
                                    ->when($record !== null, fn ($query) => $query->whereKeyNot($record->getKey())),
                            )),
                        TextInput::make('portal_password')
                            ->label(__('Portal password'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->visible(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(false)
                            ->helperText(__('Initial password for this member\'s own login account.')),
                    ]),
                self::detailSection(__('Portal & household'), __('Login and household linkage — shown in the summary panel when relevant.'))
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
                            ->visible(false),
                        Toggle::make('direct_login_enabled')
                            ->label(__('Direct login enabled'))
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(false),
                    ]),
            ]);
    }

    private static function detailSection(string $heading, ?string $description = null): Section
    {
        $section = Section::make($heading)
            ->compact()
            ->secondary()
            ->columnSpanFull();

        if ($description !== null) {
            $section->description($description);
        }

        return $section;
    }
}
