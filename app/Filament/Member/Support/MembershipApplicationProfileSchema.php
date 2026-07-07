<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\MembershipApplication;
use App\Support\BusinessDay;
use App\Support\PublicPageSettings;
use App\Support\StorageFilename;
use App\Support\Tenant\CurrentMember;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class MembershipApplicationProfileSchema
{
    /**
     * @return list<Section>
     */
    public static function sections(): array
    {
        return [
            Section::make(__('Profile'))
                ->icon('heroicon-o-identification')
                ->schema([
                    Select::make('gender')
                        ->options(MembershipApplication::genderOptions())
                        ->placeholder(__('—')),
                    Select::make('marital_status')
                        ->label(__('Marital status'))
                        ->options(MembershipApplication::maritalStatusOptions())
                        ->placeholder(__('—')),
                ])
                ->columns(2),

            Section::make(__('Identity & address'))
                ->icon('heroicon-o-map-pin')
                ->schema([
                    TextInput::make('national_id')
                        ->label(__('National ID'))
                        ->maxLength(20),
                    DatePicker::make('date_of_birth')
                        ->label(__('Date of birth'))
                        ->native(false)
                        ->maxDate(BusinessDay::now()),
                    TextInput::make('city')
                        ->label(__('City'))
                        ->maxLength(100),
                    Textarea::make('address')
                        ->label(__('Address'))
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Section::make(__('Contact'))
                ->icon('heroicon-o-phone')
                ->schema([
                    TextInput::make('mobile_phone')
                        ->label(__('Mobile phone'))
                        ->tel()
                        ->maxLength(30)
                        ->helperText(__('Primary contact number for SMS and WhatsApp notifications.')),
                    TextInput::make('home_phone')
                        ->label(__('Home phone'))
                        ->tel()
                        ->maxLength(30),
                    TextInput::make('work_phone')
                        ->label(__('Work phone'))
                        ->tel()
                        ->maxLength(30),
                ])
                ->columns(3),

            Section::make(__('Work & residency'))
                ->icon('heroicon-o-building-office')
                ->schema([
                    TextInput::make('work_place')
                        ->label(__('Work place'))
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('residency_place')
                        ->label(__('Residency place'))
                        ->maxLength(255)
                        ->columnSpanFull(),
                ]),

            Section::make(__('Employment'))
                ->icon('heroicon-o-briefcase')
                ->schema([
                    TextInput::make('occupation')
                        ->maxLength(150),
                    TextInput::make('employer')
                        ->maxLength(150),
                    TextInput::make('monthly_income')
                        ->label(__('Monthly income (:currency)', ['currency' => MoneyDisplay::symbol()]))
                        ->numeric()
                        ->prefix(MoneyDisplay::symbol())
                        ->minValue(0),
                ])
                ->columns(3),

            Section::make(__('Banking'))
                ->icon('heroicon-o-building-library')
                ->description(__('Used for cash-out withdrawals and membership records.'))
                ->schema([
                    TextInput::make('bank_account_number')
                        ->label(__('Bank account number'))
                        ->maxLength(50),
                    TextInput::make('iban')
                        ->label(__('IBAN'))
                        ->maxLength(34)
                        ->extraInputAttributes(['dir' => 'ltr', 'class' => 'font-mono']),
                ])
                ->columns(2),

            Section::make(__('Next of kin'))
                ->icon('heroicon-o-user-group')
                ->schema([
                    TextInput::make('next_of_kin_name')
                        ->label(__('Name'))
                        ->maxLength(150),
                    TextInput::make('next_of_kin_phone')
                        ->label(__('Phone'))
                        ->tel()
                        ->maxLength(30),
                ])
                ->columns(2),

            Section::make(__('Application fee'))
                ->icon('heroicon-o-banknotes')
                ->description(__('Subscription fee transfer details from your original application.'))
                ->schema([
                    TextInput::make('membership_fee_amount')
                        ->label(__('Declared transfer amount'))
                        ->numeric()
                        ->prefix(MoneyDisplay::symbol())
                        ->minValue(0),
                    DatePicker::make('membership_fee_transfer_date')
                        ->label(__('Transfer date'))
                        ->native(false)
                        ->maxDate(BusinessDay::now()),
                    TextInput::make('membership_fee_transfer_reference')
                        ->label(__('Transfer reference'))
                        ->maxLength(255),
                    FileUpload::make('membership_fee_receipt_path')
                        ->label(__('Transfer receipt'))
                        ->disk('public')
                        ->directory('applications/receipts')
                        ->downloadable()
                        ->openable()
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make(__('Notes'))
                ->schema([
                    Textarea::make('message')
                        ->label(__('Applicant message'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Section::make(__('Signed application form'))
                ->icon('heroicon-o-document-text')
                ->description(new HtmlString(view('filament.tenant.membership-application-form-upload-notice', [
                    'downloadUrl' => PublicPageSettings::membershipApplicationFormUploadDownloadUrl(),
                ])->render()))
                ->schema([
                    FileUpload::make('application_form_path')
                        ->label(__('Signed application form'))
                        ->disk('public')
                        ->directory('applications')
                        ->getUploadedFileNameForStorageUsing(
                            fn (TemporaryUploadedFile $file): string => StorageFilename::make(
                                'application-form',
                                $file->getClientOriginalName(),
                                [
                                    auth('tenant')->user()?->name,
                                    CurrentMember::id(),
                                ],
                            ),
                        )
                        ->downloadable()
                        ->openable()
                        ->acceptedFileTypes([
                            'application/pdf',
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                        ])
                        ->maxSize(10240)
                        ->helperText(__('PDF or image, max 10 MB.')),
                ]),
        ];
    }
}
