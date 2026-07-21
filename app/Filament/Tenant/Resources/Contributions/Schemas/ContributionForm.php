<?php

namespace App\Filament\Tenant\Resources\Contributions\Schemas;

use App\Filament\Support\MemberSelect;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Support\BusinessDay;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContributionForm
{
    public static function configure(Schema $schema, ?Contribution $record = null): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $forCreate = $record === null;
        $coreEditable = $forCreate || $record->isCoreEditableByAdmin();
        $metadataEditable = $forCreate || $record->isMetadataEditableByAdmin();

        return $schema
            ->components([
                Section::make(__('Contribution details'))
                    ->columns(2)
                    ->schema([
                        MemberSelect::make('member_id')
                            ->required()
                            ->disabled(! $coreEditable)
                            ->dehydrated($coreEditable)
                            ->reactive()
                            ->afterStateUpdated(fn ($state, callable $set) => $set(
                                'amount',
                                Member::find($state)?->monthly_contribution_amount ?? 0
                            )),
                        DatePicker::make('period')
                            ->required()
                            ->default(BusinessDay::today()->startOfMonth())
                            ->disabled(! $coreEditable)
                            ->dehydrated($coreEditable),
                        TextInput::make('amount')
                            ->numeric()
                            ->prefix($currency)
                            ->required()
                            ->minValue(0)
                            ->disabled(! $coreEditable)
                            ->dehydrated($coreEditable),
                        TextInput::make('status')
                            ->label(__('Status'))
                            ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : '')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(! $forCreate),
                        TextInput::make('payment_method')
                            ->label(__('Source'))
                            ->formatStateUsing(fn (?string $state): string => filled($state)
                                ? (Contribution::paymentMethodOptions()[$state] ?? ucfirst(str_replace('_', ' ', (string) $state)))
                                : '')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(! $forCreate),
                    ]),
                Section::make(__('Reference'))
                    ->schema([
                        TextInput::make('reference_number')
                            ->label(__('Reference number'))
                            ->maxLength(255)
                            ->disabled(! $metadataEditable)
                            ->dehydrated($metadataEditable),
                        Textarea::make('notes')
                            ->label(__('Notes'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->disabled(! $metadataEditable)
                            ->dehydrated($metadataEditable),
                    ]),
            ]);
    }
}
