<?php

namespace App\Filament\Tenant\Resources\MembershipApplications\Schemas;

use App\Models\Tenant\MembershipApplication;
use App\Support\PublicPageSettings;
use App\Support\StorageFilename;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MembershipApplicationForm
{
    public static function configure(Schema $schema, bool $forCreate = false): Schema
    {
        $accountFields = [
            TextInput::make('name')
                ->label(__('Full name'))
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label(__('Email (login)'))
                ->email()
                ->required()
                ->maxLength(255)
                ->helperText($forCreate ? __('Stored on the application until approval creates the member login.') : null),
        ];

        if ($forCreate) {
            $accountFields[] = TextInput::make('password')
                ->label(__('Password'))
                ->password()
                ->revealable()
                ->required()
                ->minLength(8)
                ->same('password_confirmation');
            $accountFields[] = TextInput::make('password_confirmation')
                ->label(__('Confirm password'))
                ->password()
                ->revealable()
                ->required()
                ->dehydrated(false);
        } else {
            $accountFields[] = TextInput::make('password')
                ->label(__('Password'))
                ->password()
                ->revealable()
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(__('Leave blank to keep the stored password unchanged.'));
            $accountFields[] = TextInput::make('phone')
                ->label(__('Phone (legacy)'))
                ->tel()
                ->maxLength(30);
        }

        $detailsSchema = [
            Section::make(__('Profile'))
                ->icon('heroicon-o-identification')
                ->schema([
                    Select::make('application_type')
                        ->label(__('Application type'))
                        ->options(MembershipApplication::applicationTypeOptions())
                        ->required()
                        ->default($forCreate ? 'new' : null),
                    Select::make('gender')
                        ->options(MembershipApplication::genderOptions())
                        ->placeholder(__('—')),
                    Select::make('marital_status')
                        ->label(__('Marital status'))
                        ->options(MembershipApplication::maritalStatusOptions())
                        ->placeholder(__('—')),
                    DatePicker::make('membership_date')
                        ->label(__('Membership date'))
                        ->native(false),
                ])->columns(2),

            Section::make(__('Identity & address'))
                ->icon('heroicon-o-map-pin')
                ->schema([
                    TextInput::make('national_id')
                        ->label(__('National ID'))
                        ->maxLength(20),
                    DatePicker::make('date_of_birth')
                        ->label(__('Date of birth'))
                        ->native(false)
                        ->maxDate(now()),
                    TextInput::make('city')
                        ->label(__('City'))
                        ->maxLength(100),
                    Textarea::make('address')
                        ->label(__('Address'))
                        ->rows(3)
                        ->columnSpanFull(),
                ])->columns(3),

            Section::make(__('Contact'))
                ->icon('heroicon-o-phone')
                ->schema([
                    TextInput::make('mobile_phone')
                        ->label(__('Mobile phone'))
                        ->tel()
                        ->maxLength(30),
                    TextInput::make('home_phone')
                        ->label(__('Home phone'))
                        ->tel()
                        ->maxLength(30),
                    TextInput::make('work_phone')
                        ->label(__('Work phone'))
                        ->tel()
                        ->maxLength(30),
                ])->columns(3),

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
                        ->label(__('Monthly income (SAR)'))
                        ->numeric()
                        ->prefix(__('SAR'))
                        ->minValue(0),
                ])->columns(3),

            Section::make(__('Banking'))
                ->icon('heroicon-o-building-library')
                ->schema([
                    TextInput::make('bank_account_number')
                        ->label(__('Bank account number'))
                        ->maxLength(50),
                    TextInput::make('iban')
                        ->label(__('IBAN'))
                        ->maxLength(34)
                        ->extraInputAttributes(['dir' => 'ltr', 'class' => 'font-mono']),
                ])->columns(2),

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
                ])->columns(2),

            Section::make(__('Application fee'))
                ->icon('heroicon-o-banknotes')
                ->schema([
                    TextInput::make('membership_fee_amount')
                        ->label(__('Fee amount (SAR)'))
                        ->numeric()
                        ->prefix(__('SAR'))
                        ->minValue(0),
                    TextInput::make('membership_fee_transfer_reference')
                        ->label(__('Transfer reference'))
                        ->maxLength(255),
                ])->columns(2),

            Section::make(__('Notes'))
                ->schema([
                    Textarea::make('message')
                        ->label(__('Applicant message'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ];

        if (! $forCreate) {
            $detailsSchema[] = Section::make(__('Review status'))
                ->icon('heroicon-o-clipboard-document-check')
                ->description(__('Use Approve / Reject on the list for workflow actions. You can correct the rejection reason here.'))
                ->schema([
                    Select::make('status')
                        ->options(MembershipApplication::statusOptions())
                        ->disabled()
                        ->dehydrated(false),
                    Textarea::make('rejection_reason')
                        ->label(__('Rejection reason'))
                        ->rows(3)
                        ->columnSpanFull(),
                    DatePicker::make('reviewed_at')
                        ->label(__('Reviewed at'))
                        ->disabled()
                        ->dehydrated(false),
                ])->columns(2);
        }

        return $schema
            ->components([
                Tabs::make()
                    ->contained(false)
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make(__('Account'))
                            ->icon('heroicon-o-user')
                            ->schema([
                                Section::make(__('Applicant account'))
                                    ->icon('heroicon-o-user')
                                    ->description(__('Login credentials submitted with the application.'))
                                    ->schema($accountFields)
                                    ->columns(2),
                            ]),

                        Tab::make(__('Details'))
                            ->icon('heroicon-o-clipboard-document-list')
                            ->schema($detailsSchema),

                        Tab::make(__('Form upload'))
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Section::make()
                                    ->description(function (): ?HtmlString {
                                        $downloadUrl = PublicPageSettings::membershipApplicationDocumentUrl()
                                            ?? PublicPageSettings::termsAndConditionsDownloadUrl();

                                        if ($downloadUrl === null) {
                                            return null;
                                        }

                                        return new HtmlString(
                                            '<a class="text-primary-600 underline" href="'
                                            .e($downloadUrl)
                                            .'" target="_blank" rel="noopener noreferrer">'
                                            .e(__('Download membership application form template'))
                                            .'</a>'
                                        );
                                    })
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
                                                        auth('tenant')->id() ? 'admin-'.auth('tenant')->id() : null,
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
                            ]),
                    ]),
            ]);
    }
}
