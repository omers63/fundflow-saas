<?php

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Models\Tenant\BankTemplate;
use App\Models\Tenant\Setting;
use App\Support\Lang;
use App\Support\PublicPageSettings;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.tenant.pages.settings';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public function mount(): void
    {
        $general = Setting::getGroup('general');
        $contribution = Setting::getGroup('contribution');
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
            'cycle_start_day' => $contribution['cycle_start_day'] ?? 6,
            'bank_templates' => $templates,
            'fund_name' => $public['fund_name'] ?? $general['fund_name'] ?? '',
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
                            ]),
                        Tab::make(__('Public page'))
                            ->icon('heroicon-o-globe-alt')
                            ->schema([
                                Section::make(__('Fund identity'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('fund_name')
                                            ->label(__('Fund name'))
                                            ->required()
                                            ->maxLength(255)
                                            ->helperText(__('Shown on the public landing and membership pages.')),
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
                    Select::make('date_format')
                        ->label('Date format')
                        ->options(Lang::transOptions([
                            'Y-m-d' => 'YYYY-MM-DD (2026-01-15)',
                            'd/m/Y' => 'DD/MM/YYYY (15/01/2026)',
                            'm/d/Y' => 'MM/DD/YYYY (01/15/2026)',
                            'd-m-Y' => 'DD-MM-YYYY (15-01-2026)',
                            'd.m.Y' => 'DD.MM.YYYY (15.01.2026)',
                        ]))
                        ->required(),
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
        Setting::set('contribution', 'cycle_start_day', $state['cycle_start_day']);

        PublicPageSettings::save([
            'fund_name' => $state['fund_name'],
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
                'date_format' => $templateData['date_format'],
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
