<?php

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Resources\FundTiers\FundTierResource;
use App\Filament\Tenant\Resources\LoanTiers\LoanTierResource;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\BankTemplate;
use App\Models\Tenant\Setting;
use App\Support\ArabicDisplaySettings;
use App\Support\CommunicationSettings;
use App\Support\ContributionPolicySettings;
use App\Support\ImportDateFormats;
use App\Support\Lang;
use App\Support\LoanSettings;
use App\Support\MemberNumberSettings;
use App\Support\NotificationSettings;
use App\Support\PublicPageSettings;
use App\Support\StatementSettings;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $slug = 'settings';

    protected static string|\UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?int $navigationSort = TenantNavigation::SORT_SETTINGS;

    protected string $view = 'filament.tenant.pages.settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public function mount(): void
    {
        $general = Setting::getGroup('general');
        $contribution = Setting::getGroup('contribution');
        $loan = LoanSettings::all();
        $notifications = NotificationSettings::all();
        $memberNumber = MemberNumberSettings::all();
        $public = PublicPageSettings::all();

        $templates = BankTemplate::orderBy('name')->get()->map(fn (BankTemplate $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'encoding' => $t->encoding ?? 'UTF-8',
            'delimiter' => $t->delimiter,
            'has_header' => $t->has_header,
            'skip_rows' => $t->skip_rows,
            'date_format' => $t->date_format,
            'date_column' => $t->date_column,
            'amount_mode' => $t->amount_mode ?? 'single',
            'amount_column' => $t->amount_column,
            'credit_column' => $t->credit_column ?? '',
            'debit_column' => $t->debit_column ?? '',
            'extra_columns' => self::extraColumnsForForm($t),
            'duplicate_fields' => $t->duplicate_fields ?? ['date', 'amount', 'description', 'reference'],
            'duplicate_date_tolerance' => $t->duplicate_date_tolerance ?? 0,
            'is_default' => $t->is_default,
        ])->toArray();

        $this->form->fill([
            'currency' => $general['currency'] ?? 'USD',
            'member_number_prefix' => $memberNumber['prefix'],
            'member_number_separator' => $memberNumber['separator'],
            'member_number_padding' => $memberNumber['padding'],
            'member_number_include_year' => (bool) $memberNumber['include_year'],
            'cycle_start_day' => $contribution['cycle_start_day'] ?? 6,
            'loan_eligibility_months' => $loan['eligibility_months'] ?? 12,
            'loan_min_fund_balance' => $loan['min_fund_balance'] ?? 6000,
            'loan_max_borrow_multiplier' => $loan['max_borrow_multiplier'] ?? 2,
            'loan_default_interest_rate' => $loan['default_interest_rate'] ?? 10,
            'loan_default_term_months' => $loan['default_term_months'] ?? 12,
            'loan_max_loan_amount' => $loan['max_loan_amount'] ?? 0,
            'loan_settlement_threshold_pct' => ($loan['settlement_threshold_pct'] ?? 0.16) * 100,
            'loan_require_guarantor_above_fund' => (bool) ($loan['require_guarantor_above_fund_balance'] ?? true),
            'loan_auto_allocate_repayment' => (bool) ($loan['auto_allocate_loan_repayment'] ?? false),
            'loan_default_grace_cycles' => $loan['default_grace_cycles'] ?? 2,
            ...ContributionPolicySettings::allForForm(),
            ...StatementSettings::allForForm(),
            ...CommunicationSettings::allForForm(),
            'notifications_sms_enabled' => (bool) ($notifications['sms_enabled'] ?? false),
            'notifications_whatsapp_enabled' => (bool) ($notifications['whatsapp_enabled'] ?? false),
            'notifications_twilio_sid' => $notifications['twilio_account_sid'] ?? '',
            'notifications_twilio_token' => $notifications['twilio_auth_token'] ?? '',
            'notifications_twilio_sms_from' => $notifications['twilio_sms_from'] ?? '',
            'notifications_twilio_whatsapp_from' => $notifications['twilio_whatsapp_from'] ?? '',
            'bank_templates' => $templates,
            'fund_name_en' => filled($public['fund_name_en'] ?? null)
                ? $public['fund_name_en']
                : ($general['fund_name'] ?? 'Family Fund'),
            'fund_name_ar' => $public['fund_name_ar'] ?? 'صندوق العائلة',
            'membership_no_limit' => filter_var($public['membership_no_limit'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'membership_max_members' => $public['membership_max_members'] ?? '',
            'fee_new' => $public['fee_new'] ?? '0',
            'fee_resume' => $public['fee_resume'] ?? '0',
            'fee_renew' => $public['fee_renew'] ?? '0',
            'rules_and_conditions_url' => $public['rules_and_conditions_url'] ?? '',
            'membership_application_document_url' => $public['membership_application_document_url'] ?? '',
            'fee_transfer_bank_name' => $public['fee_transfer_bank_name'] ?? '',
            'fee_transfer_iban' => $public['fee_transfer_iban'] ?? '',
            'contact_email' => $public['contact_email'] ?? '',
            'contact_phone' => $public['contact_phone'] ?? '',
            'fund_logo' => filled($public['fund_logo'] ?? '') ? [$public['fund_logo']] : [],
            ...ArabicDisplaySettings::allForForm(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Tabs::make('Settings')
                    ->tabs([
                        Tab::make('General')
                            ->icon('heroicon-o-cog-6-tooth')
                            ->schema([
                                Section::make(__('Regional'))
                                    ->columns(2)
                                    ->schema([
                                        Select::make('currency')
                                            ->label('Currency')
                                            ->required()
                                            ->searchable()
                                            ->options(static::currencyOptions())
                                            ->helperText(__('The primary currency used for all transactions.')),
                                    ]),
                                Section::make(__('Member numbers'))
                                    ->description(__('Controls how IDs are generated for new members (manual create and approved applications). Existing numbers are not changed.'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('member_number_prefix')
                                            ->label(__('Prefix'))
                                            ->required()
                                            ->maxLength(20)
                                            ->regex('/^[A-Za-z0-9]+$/')
                                            ->validationMessages([
                                                'regex' => __('Use letters and numbers only.'),
                                            ])
                                            ->live(onBlur: true)
                                            ->helperText(__('Stored in uppercase (e.g. MEM, FUND).')),
                                        Select::make('member_number_separator')
                                            ->label(__('Separator'))
                                            ->options(MemberNumberSettings::separatorOptions())
                                            ->required()
                                            ->live(),
                                        TextInput::make('member_number_padding')
                                            ->label(__('Sequence digits'))
                                            ->numeric()
                                            ->minValue(3)
                                            ->maxValue(8)
                                            ->required()
                                            ->live(onBlur: true)
                                            ->helperText(__('How many digits to use for the running number (e.g. 4 → 0001).')),
                                        Toggle::make('member_number_include_year')
                                            ->label(__('Include calendar year'))
                                            ->live()
                                            ->helperText(__('When enabled, the year is inserted before the sequence (e.g. MEM-2026-0001). The sequence restarts each year.')),
                                        Placeholder::make('member_number_preview')
                                            ->label(__('Next number preview'))
                                            ->columnSpanFull()
                                            ->content(function (Get $get): string {
                                                return MemberNumberSettings::preview([
                                                    'prefix' => $get('member_number_prefix'),
                                                    'separator' => $get('member_number_separator'),
                                                    'padding' => $get('member_number_padding'),
                                                    'include_year' => (bool) $get('member_number_include_year'),
                                                ]);
                                            })
                                            ->helperText(__('Based on existing members matching this pattern.')),
                                    ]),
                            ]),
                        Tab::make(__('Public page'))
                            ->icon('heroicon-o-globe-alt')
                            ->schema([
                                Section::make(__('Fund identity'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('fund_name_en')
                                            ->label(__('Fund name (English)'))
                                            ->required()
                                            ->maxLength(255)
                                            ->helperText(__('Shown on public pages and panels when the interface is in English.')),
                                        TextInput::make('fund_name_ar')
                                            ->label(__('Fund name (Arabic)'))
                                            ->required()
                                            ->maxLength(255)
                                            ->helperText(__('Shown on public pages and panels when the interface is in Arabic.')),
                                        Select::make('arabic_display_font')
                                            ->label(__('Arabic typeface'))
                                            ->options(ArabicDisplaySettings::fontOptions())
                                            ->required()
                                            ->native(false)
                                            ->helperText(__('Font used for Arabic interface text. Member names in tables use the same typeface.')),
                                        Toggle::make('arabic_enhanced_name_style')
                                            ->label(__('Enhanced Arabic member names'))
                                            ->helperText(__('Larger display and explicit right-to-left layout for Arabic names in tables and headings. When off, names use normal size with RTL text only.')),
                                        FileUpload::make('fund_logo')
                                            ->label(__('Fund logo'))
                                            ->image()
                                            ->disk('public')
                                            ->directory('fund-branding')
                                            ->maxSize(2048)
                                            ->acceptedFileTypes([
                                                'image/png',
                                                'image/jpeg',
                                                'image/webp',
                                                'image/svg+xml',
                                            ])
                                            ->columnSpanFull()
                                            ->helperText(__('Optional. Replaces the default FundFlow logo in the navbar, footer, Filament panels, browser tab, and PWA icon. Square images work best.')),
                                    ]),
                                Section::make(__('Public contact'))
                                    ->description(__('Shown in the site footer on public pages.'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('contact_email')
                                            ->label(__('Contact email'))
                                            ->email()
                                            ->maxLength(255),
                                        TextInput::make('contact_phone')
                                            ->label(__('Contact phone'))
                                            ->tel()
                                            ->maxLength(50),
                                    ]),
                                Section::make(__('Membership control'))
                                    ->description(__('Limit how many active members may enroll in the fund.'))
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('membership_no_limit')
                                            ->label(__('No member limit'))
                                            ->live()
                                            ->helperText(__('When enabled, enrollment is always open regardless of member count.')),
                                        TextInput::make('membership_max_members')
                                            ->label(__('Maximum active members'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->required(fn (Get $get): bool => ! ($get('membership_no_limit') ?? true))
                                            ->hidden(fn (Get $get): bool => (bool) ($get('membership_no_limit') ?? true))
                                            ->helperText(__('Enrollment closes when active members reach this number.')),
                                    ]),
                                Section::make(__('Membership fees'))
                                    ->description(__('Fees displayed during public enrollment (New, Resume, Renew).'))
                                    ->columns(3)
                                    ->schema([
                                        TextInput::make('fee_new')
                                            ->label(__('New membership fee'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                        TextInput::make('fee_resume')
                                            ->label(__('Resume membership fee'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                        TextInput::make('fee_renew')
                                            ->label(__('Renew membership fee'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                    ]),
                                Section::make(__('Fee transfer bank account'))
                                    ->description(__('Bank details shown on the membership application when a fee applies. Applicants transfer the fee to this account before submitting.'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('fee_transfer_bank_name')
                                            ->label(__('Bank name'))
                                            ->maxLength(255)
                                            ->placeholder(__('e.g. Al Rajhi Bank')),
                                        TextInput::make('fee_transfer_iban')
                                            ->label(__('IBAN'))
                                            ->maxLength(34)
                                            ->placeholder('SA0000000000000000000000')
                                            ->helperText(__('International Bank Account Number for fee payments.')),
                                    ]),
                                Section::make(__('Public documents'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('rules_and_conditions_url')
                                            ->label(__('Rules and conditions URL'))
                                            ->url()
                                            ->maxLength(2048)
                                            ->placeholder('https://…'),
                                        TextInput::make('membership_application_document_url')
                                            ->label(__('Membership application document URL'))
                                            ->url()
                                            ->maxLength(2048)
                                            ->placeholder('https://…'),
                                    ]),
                            ]),
                        Tab::make('Contributions')
                            ->icon('heroicon-o-calendar')
                            ->schema([
                                Section::make(__('Contribution Cycle'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('cycle_start_day')
                                            ->label('Cycle start day')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(28)
                                            ->required()
                                            ->helperText(__('Day of month when the contribution cycle starts (1-28). Default: 6th.')),
                                    ]),
                                Section::make(__('Delinquency policy'))
                                    ->description(__('Daily delinquency sync marks members delinquent when consecutive or rolling miss thresholds are breached.'))
                                    ->columns(3)
                                    ->schema([
                                        TextInput::make('delinquency_consecutive')
                                            ->label(__('Consecutive missed cycles'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(36)
                                            ->required(),
                                        TextInput::make('delinquency_total')
                                            ->label(__('Total misses (rolling window)'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(240)
                                            ->required(),
                                        TextInput::make('delinquency_lookback_months')
                                            ->label(__('Rolling window (months)'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(240)
                                            ->required(),
                                    ]),
                                Section::make(__('Late fees (days after cycle deadline)'))
                                    ->description(__('Highest non-zero tier reached applies (30+ → 20+ → 10+ → 1+).'))
                                    ->columns(4)
                                    ->schema([
                                        TextInput::make('late_fee_contribution_1d')
                                            ->label(__('Contribution — 1+ days late'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                        TextInput::make('late_fee_contribution_10d')
                                            ->label(__('Contribution — 10+ days late'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                        TextInput::make('late_fee_contribution_20d')
                                            ->label(__('Contribution — 20+ days late'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                        TextInput::make('late_fee_contribution_30d')
                                            ->label(__('Contribution — 30+ days late'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                        TextInput::make('late_fee_repayment_1d')
                                            ->label(__('Repayment — 1+ days late'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                        TextInput::make('late_fee_repayment_10d')
                                            ->label(__('Repayment — 10+ days late'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                        TextInput::make('late_fee_repayment_20d')
                                            ->label(__('Repayment — 20+ days late'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                        TextInput::make('late_fee_repayment_30d')
                                            ->label(__('Repayment — 30+ days late'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                    ]),
                                Section::make(__('Annual subscription fee'))
                                    ->schema([
                                        TextInput::make('annual_subscription_fee')
                                            ->label(__('Annual subscription fee'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required()
                                            ->helperText(__('Charged on join-date anniversary; set to 0 to disable.')),
                                    ]),
                            ]),
                        Tab::make(__('Loans'))
                            ->icon('heroicon-o-banknotes')
                            ->schema([
                                Section::make(__('Eligibility'))
                                    ->description(__('Rules applied when members apply for a loan or admins create an application.'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('loan_eligibility_months')
                                            ->label(__('Minimum membership (months)'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(120)
                                            ->required(),
                                        TextInput::make('loan_min_fund_balance')
                                            ->label(__('Minimum fund balance'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required()
                                            ->helperText(__('Member fund account must meet this balance to apply.')),
                                        TextInput::make('loan_max_borrow_multiplier')
                                            ->label(__('Max borrow multiplier'))
                                            ->numeric()
                                            ->minValue(0.1)
                                            ->step(0.1)
                                            ->required()
                                            ->helperText(__('Maximum loan = fund balance × this multiplier (unless capped below).')),
                                        TextInput::make('loan_max_loan_amount')
                                            ->label(__('Absolute max loan amount'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->helperText(__('Optional hard cap (0 = no cap, use multiplier only).')),
                                    ]),
                                Section::make(__('Defaults'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('loan_default_interest_rate')
                                            ->label(__('Default interest rate (%)'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(100)
                                            ->required(),
                                        TextInput::make('loan_default_term_months')
                                            ->label(__('Default term (months)'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(120)
                                            ->required(),
                                        TextInput::make('loan_settlement_threshold_pct')
                                            ->label(__('Settlement threshold (%)'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(100)
                                            ->step(0.1)
                                            ->required()
                                            ->helperText(__('Percentage of approved amount member must hold in fund for full settlement.')),
                                    ]),
                                Section::make(__('Repayment & guarantors'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('loan_default_grace_cycles')
                                            ->label(__('Grace cycles before guarantor debit'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(24)
                                            ->required()
                                            ->helperText(__('Missed repayment cycles before guarantor liability steps apply.')),
                                        Toggle::make('loan_require_guarantor_above_fund')
                                            ->label(__('Require guarantor above fund balance'))
                                            ->helperText(__('When the requested amount exceeds the member fund balance, a guarantor is mandatory on apply.')),
                                        Toggle::make('loan_auto_allocate_repayment')
                                            ->label(__('Auto-allocate posted contributions to loan'))
                                            ->helperText(__('After a contribution is posted, apply open-period loan repayment from member cash when possible.')),
                                    ]),
                                Section::make(__('Loan & fund tiers'))
                                    ->description(__('Interest rates and fund multipliers are managed in dedicated resources.'))
                                    ->schema([
                                        Placeholder::make('loan_tiers_link')
                                            ->label(__('Loan tiers'))
                                            ->content(fn (): string => LoanTierResource::getUrl('index')),
                                        Placeholder::make('fund_tiers_link')
                                            ->label(__('Fund tiers'))
                                            ->content(fn (): string => FundTierResource::getUrl('index')),
                                    ]),
                            ]),
                        Tab::make(__('Statements'))
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Section::make(__('Branding'))
                                    ->columns(3)
                                    ->schema([
                                        TextInput::make('statement_brand_name')
                                            ->label(__('Organization name'))
                                            ->required()
                                            ->maxLength(80),
                                        TextInput::make('statement_tagline')
                                            ->label(__('Tagline'))
                                            ->maxLength(120),
                                        TextInput::make('statement_accent_color')
                                            ->label(__('Header accent color (hex)'))
                                            ->required()
                                            ->maxLength(7)
                                            ->placeholder('#059669'),
                                    ]),
                                Section::make(__('Footer & signature'))
                                    ->schema([
                                        Textarea::make('statement_footer_disclaimer')
                                            ->label(__('Footer disclaimer'))
                                            ->rows(2)
                                            ->columnSpanFull(),
                                        TextInput::make('statement_signature_line')
                                            ->label(__('Authorized signature line'))
                                            ->maxLength(100),
                                    ]),
                                Section::make(__('Delivery & content'))
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('statement_auto_email')
                                            ->label(__('Auto-email members on generation'))
                                            ->helperText(__('When enabled, statement notifications may include email when the email channel is on.')),
                                        Toggle::make('statement_include_transactions')
                                            ->label(__('Include transaction detail table')),
                                        Toggle::make('statement_include_loan_section')
                                            ->label(__('Include loan standing section')),
                                        Toggle::make('statement_include_compliance')
                                            ->label(__('Include compliance snapshot')),
                                    ]),
                            ]),
                        Tab::make(__('Communication'))
                            ->icon('heroicon-o-chat-bubble-left-right')
                            ->schema([
                                Section::make(__('Communication channels'))
                                    ->description(__('When disabled, no notifications are sent through that channel. SMS and WhatsApp credentials are configured under Notifications.'))
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('communication_in_app_enabled')
                                            ->label(__('In-app inbox')),
                                        Toggle::make('communication_email_enabled')
                                            ->label(__('Email')),
                                    ]),
                            ]),
                        Tab::make(__('Notifications'))
                            ->icon('heroicon-o-bell-alert')
                            ->schema([
                                Section::make(__('Member SMS & WhatsApp'))
                                    ->description(__('Uses Twilio when enabled. Members must have a phone number on their profile.'))
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('notifications_sms_enabled')
                                            ->label(__('Enable SMS'))
                                            ->live(),
                                        Toggle::make('notifications_whatsapp_enabled')
                                            ->label(__('Enable WhatsApp'))
                                            ->live(),
                                        TextInput::make('notifications_twilio_sid')
                                            ->label(__('Twilio account SID'))
                                            ->maxLength(64)
                                            ->columnSpanFull(),
                                        TextInput::make('notifications_twilio_token')
                                            ->label(__('Twilio auth token'))
                                            ->password()
                                            ->revealable()
                                            ->maxLength(128)
                                            ->columnSpanFull(),
                                        TextInput::make('notifications_twilio_sms_from')
                                            ->label(__('SMS sender number'))
                                            ->tel()
                                            ->helperText(__('E.164 format, e.g. +14155552671')),
                                        TextInput::make('notifications_twilio_whatsapp_from')
                                            ->label(__('WhatsApp sender'))
                                            ->helperText(__('Twilio WhatsApp-enabled number, e.g. +14155238886')),
                                    ]),
                            ]),
                        Tab::make('Bank Templates')
                            ->icon('heroicon-o-document-arrow-up')
                            ->schema([
                                Section::make(__('CSV Import Templates'))
                                    ->description(__('Define templates for parsing bank statement CSV files. Select one as default.'))
                                    ->schema([
                                        Repeater::make('bank_templates')
                                            ->label('')
                                            ->schema(static::templateSchema())
                                            ->columns(1)
                                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? __('New template'))
                                            ->collapsible()
                                            ->defaultItems(0)
                                            ->addActionLabel(__('Add template')),
                                    ]),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, mixed>
     */
    private static function templateSchema(): array
    {
        return [
            // --- Section 1: General ---
            Fieldset::make('General')
                ->columns(3)
                ->schema([
                    TextInput::make('name')
                        ->label('Template name')
                        ->required()
                        ->maxLength(255),
                    Select::make('encoding')
                        ->label('File encoding')
                        ->options(Lang::transOptions([
                            'UTF-8' => 'UTF-8',
                            'ISO-8859-1' => 'ISO-8859-1 (Latin-1)',
                            'Windows-1252' => 'Windows-1252',
                            'ASCII' => 'ASCII',
                        ]))
                        ->default('UTF-8')
                        ->required(),
                    Checkbox::make('is_default')
                        ->label('Default template'),
                    Select::make('delimiter')
                        ->label('Delimiter')
                        ->options(Lang::transOptions([
                            ',' => 'Comma (,)',
                            ';' => 'Semicolon (;)',
                            "\t" => 'Tab',
                            '|' => 'Pipe (|)',
                        ]))
                        ->required(),
                    CheckboxList::make('date_format')
                        ->label('Date formats')
                        ->options(Lang::transOptions(ImportDateFormats::options()))
                        ->columns(1)
                        ->required()
                        ->minItems(1)
                        ->helperText(__('Select every format that may appear in the file. Do not combine day/month and month/day styles (e.g. DD/MM/YYYY with MM/DD/YYYY).'))
                        ->rule(function (): \Closure {
                            return function (string $attribute, mixed $value, \Closure $fail): void {
                                $message = ImportDateFormats::contradictionMessage(
                                    ImportDateFormats::normalize(is_array($value) ? $value : [])
                                );

                                if ($message !== null) {
                                    $fail($message);
                                }
                            };
                        }),
                    TextInput::make('skip_rows')
                        ->label('Skip rows')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->helperText(__('Rows to skip before the header row (if enabled) or data. E.g. if the header is on row 16, set this to 15.')),
                ]),

            // --- Section 2: Header and columns ---
            Fieldset::make('Header and Columns')
                ->columns(2)
                ->schema([
                    Toggle::make('has_header')
                        ->label('First data row is a header')
                        ->helperText(__('On: use exact column header text in mappings. Off: use 0-based column index (0, 1, 2...).'))
                        ->default(true)
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('date_column')
                        ->label('Date column')
                        ->required()
                        ->helperText(fn (Get $get): string => ($get('has_header') ?? true)
                            ? __('Header name, e.g. "Transaction Date"')
                            : __('0-based column index, e.g. 0')),
                ]),

            // --- Section 3: Amount structure ---
            Fieldset::make('Amount Structure')
                ->columns(2)
                ->schema([
                    Radio::make('amount_mode')
                        ->label('Amount type')
                        ->options(Lang::transOptions([
                            'single' => 'One amount column (negative often means debit)',
                            'split' => 'Separate credit and debit columns',
                        ]))
                        ->default('single')
                        ->required()
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('amount_column')
                        ->label('Amount column')
                        ->required(fn (Get $get): bool => ($get('amount_mode') ?? 'single') === 'single')
                        ->visible(fn (Get $get): bool => ($get('amount_mode') ?? 'single') === 'single')
                        ->helperText(fn (Get $get): string => ($get('has_header') ?? true)
                            ? __('Header name, e.g. "Amount"')
                            : __('0-based index')),
                    TextInput::make('credit_column')
                        ->label('Credit column')
                        ->required(fn (Get $get): bool => ($get('amount_mode') ?? 'single') === 'split')
                        ->visible(fn (Get $get): bool => ($get('amount_mode') ?? 'single') === 'split'),
                    TextInput::make('debit_column')
                        ->label('Debit column')
                        ->required(fn (Get $get): bool => ($get('amount_mode') ?? 'single') === 'split')
                        ->visible(fn (Get $get): bool => ($get('amount_mode') ?? 'single') === 'split'),
                ]),

            // --- Section 4: Optional column mappings ---
            Fieldset::make('Optional Column Mappings')
                ->columns(1)
                ->schema([
                    Repeater::make('extra_columns')
                        ->label('')
                        ->live()
                        ->schema([
                            TextInput::make('key')
                                ->label('Key')
                                ->required()
                                ->placeholder(__('e.g. reference, description, balance, branch_code'))
                                ->helperText(__('Use "reference", "description", or "balance" to fill main fields. Other keys are stored on the import row.')),
                            TextInput::make('column')
                                ->label('CSV column')
                                ->required()
                                ->placeholder(__('Header name or 0-based index')),
                        ])
                        ->columns(2)
                        ->columnSpanFull()
                        ->default(BankTemplate::defaultExtraColumns())
                        ->addActionLabel(__('Add column mapping'))
                        ->itemLabel(fn (array $state): ?string => ! empty($state['key']) ? "{$state['key']} → {$state['column']}" : __('New mapping')),
                ]),

            // --- Section 5: Duplicate detection ---
            Fieldset::make('Duplicate Detection')
                ->columns(2)
                ->schema([
                    CheckboxList::make('duplicate_fields')
                        ->label('Match fields')
                        ->options(function (Get $get): array {
                            $options = [
                                'date' => 'Date',
                                'amount' => 'Amount',
                            ];

                            foreach ($get('extra_columns') ?? [] as $mapping) {
                                $key = $mapping['key'] ?? '';
                                if ($key !== '' && ! isset($options[$key])) {
                                    $options[$key] = ucfirst($key);
                                }
                            }

                            foreach ($get('duplicate_fields') ?? [] as $selected) {
                                if (is_string($selected) && $selected !== '' && ! isset($options[$selected])) {
                                    $options[$selected] = ucfirst($selected);
                                }
                            }

                            return Lang::transOptions($options);
                        })
                        ->live()
                        ->default(['date', 'amount'])
                        ->helperText(__('Transactions are considered duplicates when all selected fields match. Options come from your column mappings above.'))
                        ->columnSpanFull(),
                    TextInput::make('duplicate_date_tolerance')
                        ->label('Date tolerance (days)')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->helperText(__('Higher values allow nearby dates to match. 0 = exact day.')),
                ]),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        Setting::set('general', 'currency', $state['currency']);
        MemberNumberSettings::save([
            'prefix' => $state['member_number_prefix'],
            'separator' => $state['member_number_separator'],
            'padding' => (int) $state['member_number_padding'],
            'include_year' => (bool) ($state['member_number_include_year'] ?? false),
        ]);
        Setting::set('contribution', 'cycle_start_day', $state['cycle_start_day']);

        LoanSettings::save([
            'eligibility_months' => (int) $state['loan_eligibility_months'],
            'min_fund_balance' => (float) $state['loan_min_fund_balance'],
            'max_borrow_multiplier' => (float) $state['loan_max_borrow_multiplier'],
            'default_interest_rate' => (float) $state['loan_default_interest_rate'],
            'default_term_months' => (int) $state['loan_default_term_months'],
            'max_loan_amount' => (float) ($state['loan_max_loan_amount'] ?? 0),
            'settlement_threshold_pct' => ((float) ($state['loan_settlement_threshold_pct'] ?? 16)) / 100,
            'default_grace_cycles' => (int) ($state['loan_default_grace_cycles'] ?? 2),
            'require_guarantor_above_fund_balance' => (bool) ($state['loan_require_guarantor_above_fund'] ?? true),
            'auto_allocate_loan_repayment' => (bool) ($state['loan_auto_allocate_repayment'] ?? false),
        ]);

        ContributionPolicySettings::saveFromForm($state);
        StatementSettings::saveFromForm($state);
        CommunicationSettings::saveFromForm($state);

        NotificationSettings::save([
            'sms_enabled' => (bool) ($state['notifications_sms_enabled'] ?? false),
            'whatsapp_enabled' => (bool) ($state['notifications_whatsapp_enabled'] ?? false),
            'twilio_account_sid' => $state['notifications_twilio_sid'] ?? '',
            'twilio_auth_token' => $state['notifications_twilio_token'] ?? '',
            'twilio_sms_from' => $state['notifications_twilio_sms_from'] ?? '',
            'twilio_whatsapp_from' => $state['notifications_twilio_whatsapp_from'] ?? '',
        ]);

        ArabicDisplaySettings::save([
            'arabic_display_font' => $state['arabic_display_font'] ?? ArabicDisplaySettings::FONT_NOTO_SANS,
            'arabic_enhanced_name_style' => (bool) ($state['arabic_enhanced_name_style'] ?? false),
        ]);

        PublicPageSettings::save([
            'fund_name_en' => $state['fund_name_en'],
            'fund_name_ar' => $state['fund_name_ar'],
            'fund_logo' => $state['fund_logo'] ?? '',
            'membership_no_limit' => (bool) ($state['membership_no_limit'] ?? true),
            'membership_max_members' => $state['membership_max_members'] ?? '',
            'fee_new' => $state['fee_new'],
            'fee_resume' => $state['fee_resume'],
            'fee_renew' => $state['fee_renew'],
            'rules_and_conditions_url' => $state['rules_and_conditions_url'] ?? '',
            'membership_application_document_url' => $state['membership_application_document_url'] ?? '',
            'fee_transfer_bank_name' => $state['fee_transfer_bank_name'] ?? '',
            'fee_transfer_iban' => $state['fee_transfer_iban'] ?? '',
            'contact_email' => $state['contact_email'] ?? '',
            'contact_phone' => $state['contact_phone'] ?? '',
        ]);

        $existingIds = BankTemplate::pluck('id')->toArray();
        $keptIds = [];
        $hasDefault = false;

        foreach ($state['bank_templates'] ?? [] as $templateData) {
            $extras = collect($templateData['extra_columns'] ?? [])
                ->filter(fn (array $row): bool => ! empty($row['key'] ?? null) && ($row['column'] ?? '') !== '')
                ->values()
                ->all();

            if (($templateData['amount_mode'] ?? 'single') === 'single' && $extras === []) {
                $extras = BankTemplate::defaultExtraColumns();
            }

            $attrs = [
                'name' => $templateData['name'],
                'encoding' => $templateData['encoding'] ?? 'UTF-8',
                'delimiter' => $templateData['delimiter'],
                'has_header' => (bool) ($templateData['has_header'] ?? false),
                'skip_rows' => (int) ($templateData['skip_rows'] ?? 0),
                'date_format' => ImportDateFormats::normalize($templateData['date_format'] ?? null),
                'date_column' => $templateData['date_column'],
                'amount_mode' => $templateData['amount_mode'] ?? 'single',
                'amount_column' => $templateData['amount_column'] ?? null,
                'credit_column' => ($templateData['credit_column'] ?? null) ?: null,
                'debit_column' => ($templateData['debit_column'] ?? null) ?: null,
                'extra_columns' => $extras,
                'duplicate_fields' => $templateData['duplicate_fields'] ?? ['date', 'amount', 'description', 'reference'],
                'duplicate_date_tolerance' => (int) ($templateData['duplicate_date_tolerance'] ?? 0),
                'is_default' => (bool) ($templateData['is_default'] ?? false),
            ];

            if (! empty($templateData['id'])) {
                $template = BankTemplate::find($templateData['id']);
                if ($template) {
                    $template->update($attrs);
                    $keptIds[] = $template->id;
                } else {
                    $new = BankTemplate::create($attrs);
                    $keptIds[] = $new->id;
                }
            } else {
                $new = BankTemplate::create($attrs);
                $keptIds[] = $new->id;
            }

            if ($attrs['is_default']) {
                $hasDefault = true;
            }
        }

        $deleteIds = array_diff($existingIds, $keptIds);
        if (! empty($deleteIds)) {
            BankTemplate::whereIn('id', $deleteIds)->delete();
        }

        if ($hasDefault) {
            $defaultTemplate = BankTemplate::where('is_default', true)->latest()->first();
            if ($defaultTemplate) {
                BankTemplate::where('id', '!=', $defaultTemplate->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        }

        Notification::make()
            ->title(__('Settings saved'))
            ->success()
            ->send();
    }

    /**
     * @return array<int, array{key: string, column: string}>
     */
    private static function extraColumnsForForm(BankTemplate $t): array
    {
        $extras = $t->extra_columns ?? [];
        if (($t->amount_mode ?? 'single') === 'single' && $extras === []) {
            return BankTemplate::defaultExtraColumns();
        }

        return $extras;
    }

    /**
     * @return array<string, string>
     */
    private static function currencyOptions(): array
    {
        return [
            'USD' => 'USD – US Dollar',
            'EUR' => 'EUR – Euro',
            'GBP' => 'GBP – British Pound',
            'JPY' => 'JPY – Japanese Yen',
            'CAD' => 'CAD – Canadian Dollar',
            'AUD' => 'AUD – Australian Dollar',
            'CHF' => 'CHF – Swiss Franc',
            'CNY' => 'CNY – Chinese Yuan',
            'INR' => 'INR – Indian Rupee',
            'BRL' => 'BRL – Brazilian Real',
            'ZAR' => 'ZAR – South African Rand',
            'AED' => 'AED – UAE Dirham',
            'SAR' => 'SAR – Saudi Riyal',
            'QAR' => 'QAR – Qatari Riyal',
            'KWD' => 'KWD – Kuwaiti Dinar',
            'BHD' => 'BHD – Bahraini Dinar',
            'OMR' => 'OMR – Omani Rial',
            'JOD' => 'JOD – Jordanian Dinar',
            'EGP' => 'EGP – Egyptian Pound',
            'NGN' => 'NGN – Nigerian Naira',
            'KES' => 'KES – Kenyan Shilling',
            'GHS' => 'GHS – Ghanaian Cedi',
            'TRY' => 'TRY – Turkish Lira',
            'MXN' => 'MXN – Mexican Peso',
            'SGD' => 'SGD – Singapore Dollar',
            'HKD' => 'HKD – Hong Kong Dollar',
            'MYR' => 'MYR – Malaysian Ringgit',
            'PHP' => 'PHP – Philippine Peso',
            'THB' => 'THB – Thai Baht',
            'IDR' => 'IDR – Indonesian Rupiah',
            'KRW' => 'KRW – South Korean Won',
            'PKR' => 'PKR – Pakistani Rupee',
            'BDT' => 'BDT – Bangladeshi Taka',
            'LKR' => 'LKR – Sri Lankan Rupee',
        ];
    }
}
