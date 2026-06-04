<?php

namespace App\Filament\Tenant\Resources\Loans\Schemas;

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanEligibilityService;
use App\Support\LoanEligibilityGate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class LoanForm
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema
            ->components([
                Section::make(__('Loan application'))
                    ->columns(2)
                    ->schema([
                        Select::make('member_id')
                            ->label(__('Member'))
                            ->options(Member::active()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->disabled(fn (?string $operation): bool => $operation === 'edit'),
                        TextInput::make('amount_requested')
                            ->label(__('Amount requested'))
                            ->numeric()
                            ->prefix($currency)
                            ->required()
                            ->minValue(1),
                        Select::make('guarantor_member_id')
                            ->label(__('Guarantor'))
                            ->options(Member::active()->pluck('name', 'id'))
                            ->searchable()
                            ->nullable(),
                        Toggle::make('is_emergency')
                            ->label(__('Emergency loan')),
                        Toggle::make('has_grace_cycle')
                            ->label(__('Grace cycle'))
                            ->default(true),
                        Textarea::make('purpose')
                            ->label(__('Purpose'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Eligibility override'))
                    ->description(__('Use when the member fails standard eligibility gates. Overrides are logged for audit.'))
                    ->visible(fn (?string $operation): bool => $operation === 'create')
                    ->columns(1)
                    ->schema([
                        Toggle::make('override_eligibility')
                            ->label(__('Override eligibility'))
                            ->live()
                            ->helperText(function (Get $get): ?string {
                                $memberId = $get('member_id');
                                if (blank($memberId)) {
                                    return null;
                                }

                                $member = Member::query()->with('fundAccount')->find($memberId);
                                if ($member === null) {
                                    return null;
                                }

                                $failed = app(LoanEligibilityService::class)->getFailedGates($member);
                                if ($failed === []) {
                                    return __('This member passes all eligibility gates.');
                                }

                                return __('Blocked gates: :gates', [
                                    'gates' => implode(', ', array_map(
                                        fn (string $gate): string => (string) (LoanEligibilityGate::labels()[$gate] ?? $gate),
                                        array_keys($failed),
                                    )),
                                ]);
                            }),
                        Textarea::make('eligibility_override_reason')
                            ->label(__('Override reason'))
                            ->rows(3)
                            ->required(fn (Get $get): bool => (bool) $get('override_eligibility'))
                            ->visible(fn (Get $get): bool => (bool) $get('override_eligibility')),
                    ]),
            ]);
    }
}
