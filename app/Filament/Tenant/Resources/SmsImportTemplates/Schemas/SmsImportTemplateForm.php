<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsImportTemplates\Schemas;

use App\Support\Lang;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

final class SmsImportTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->schema([
            Tabs::make()->tabs([
                Tab::make(__('General'))->schema([
                    TextInput::make('bank_name')
                        ->label(__('Bank name (optional)'))
                        ->maxLength(100)
                        ->helperText(__('When set, duplicate detection can be scoped to imports for this bank label.')),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(100)
                        ->placeholder(__('e.g. Al-Rajhi SMS Export v1')),
                    Toggle::make('is_default')
                        ->label(__('Set as default template')),
                    Select::make('default_transaction_type')
                        ->label(__('Default type when no keyword matches'))
                        ->options(Lang::transOptions([
                            'credit' => __('Credit'),
                            'debit' => __('Debit'),
                        ]))
                        ->default('credit')
                        ->required(),
                ])->columns(2),

                Tab::make(__('CSV Format'))->schema([
                    Select::make('delimiter')
                        ->options(Lang::transOptions([
                            ',' => __('Comma  ( , )'),
                            ';' => __('Semicolon  ( ; )'),
                            "\t" => __('Tab'),
                            '|' => __('Pipe  ( | )'),
                        ]))
                        ->required()
                        ->default(','),
                    Select::make('encoding')
                        ->options([
                            'UTF-8' => 'UTF-8',
                            'ISO-8859-1' => 'ISO-8859-1 (Latin-1)',
                            'Windows-1256' => 'Windows-1256 (Arabic)',
                            'Windows-1252' => 'Windows-1252 (Western)',
                        ])
                        ->required()
                        ->default('UTF-8'),
                    Toggle::make('has_header')
                        ->label(__('File has a header row'))
                        ->default(true)
                        ->helperText(__('When enabled, use exact column header names in the mappings below. When disabled, use 0-based column indices.')),
                    TextInput::make('skip_rows')
                        ->label(__('Skip rows at start'))
                        ->numeric()
                        ->default(0)
                        ->minValue(0),
                ])->columns(2),

                Tab::make(__('Column Mapping'))->schema([
                    Section::make(__('SMS Text'))->schema([
                        TextInput::make('sms_column')
                            ->label(__('SMS text column'))
                            ->required()
                            ->helperText(__('Header name or 0-based index of the column that contains the raw SMS message.')),
                    ]),
                    Section::make(__('Date Column (optional)'))->schema([
                        TextInput::make('date_column')
                            ->label(__('Date column'))
                            ->helperText(__('If the CSV has a separate date column, specify it here. Leave blank to extract the date from the SMS text using the pattern below.')),
                        TextInput::make('date_format')
                            ->label(__('Date format (PHP)'))
                            ->default('Y-m-d H:i:s')
                            ->helperText(__('e.g. Y-m-d H:i:s · d/m/Y · d-M-Y · m/d/Y H:i')),
                    ])->columns(2),
                ]),

                Tab::make(__('SMS Parsing Rules'))->schema([
                    Section::make(__('Amount Extraction'))->schema([
                        TextInput::make('amount_pattern')
                            ->label(__('Amount regex pattern'))
                            ->placeholder('/SAR\s*(?P<amount>[\d,]+\.?\d*)/i')
                            ->helperText(__('Must contain a named capture group called "amount". Wrap with / delimiters or leave bare. Thousands commas are stripped automatically.'))
                            ->columnSpanFull(),
                        Placeholder::make('amount_hint')
                            ->hiddenLabel()
                            ->content(__('Examples: /SAR\s*(?P<amount>[\d,]+\.?\d*)/i · /Amount:\s*(?P<amount>[\d.]+)/ · /(?P<amount>[\d,]+\.\d{2})\s*SAR/i')),
                    ]),
                    Section::make(__('Date Extraction from SMS (used when no date column is mapped)'))->schema([
                        TextInput::make('date_pattern')
                            ->label(__('Date regex pattern'))
                            ->placeholder('/on\s+(?P<date>\d{2}\/\d{2}\/\d{4})/i')
                            ->helperText(__('Must contain a named capture group called "date".'))
                            ->columnSpanFull(),
                        TextInput::make('date_pattern_format')
                            ->label(__('Date format for extracted value (PHP)'))
                            ->placeholder('d/m/Y')
                            ->helperText(__('PHP format string matching the captured date string.')),
                        Placeholder::make('date_hint')
                            ->hiddenLabel()
                            ->content(__('Example pattern: /on\s+(?P<date>\d{2}\/\d{2}\/\d{4})/i → format: d/m/Y')),
                    ])->columns(2),
                    Section::make(__('Reference Extraction'))->schema([
                        TextInput::make('reference_pattern')
                            ->label(__('Reference regex pattern'))
                            ->placeholder('/[Rr]ef[:\s]+(?P<reference>\d+)/')
                            ->helperText(__('Must contain a named capture group called "reference".'))
                            ->columnSpanFull(),
                        Placeholder::make('reference_hint')
                            ->hiddenLabel()
                            ->content(__('Examples: /[Rr]ef[:\s]+(?P<reference>\w+)/ · /TRN[:\s]*(?P<reference>\d+)/i')),
                    ]),
                    Section::make(__('Transaction Type Detection'))->schema([
                        TagsInput::make('credit_keywords')
                            ->label(__('Credit keywords'))
                            ->default(['credited', 'received', 'deposit', 'credit'])
                            ->helperText(__('If any of these words (case-insensitive) are found in the SMS text, the transaction is classified as Credit.'))
                            ->separator(','),
                        TagsInput::make('debit_keywords')
                            ->label(__('Debit keywords'))
                            ->default(['debited', 'paid', 'purchase', 'debit', 'withdraw'])
                            ->helperText(__('If any of these words (case-insensitive) are found in the SMS text, the transaction is classified as Debit.'))
                            ->separator(','),
                    ])->columns(2),
                ]),

                Tab::make(__('Member Auto-match'))->schema([
                    Section::make()->schema([
                        TextInput::make('member_match_pattern')
                            ->label(__('Member regex pattern'))
                            ->placeholder('/Account[:\s]+(?P<member>\d+)/i')
                            ->helperText(__('Regex with a named capture group called "member". The extracted value will be looked up against the field below.'))
                            ->columnSpanFull(),
                        Select::make('member_match_field')
                            ->label(__('Match against'))
                            ->options(Lang::transOptions([
                                'member_number' => __('Member number'),
                                'member_name' => __('Member full name'),
                            ]))
                            ->default('member_number')
                            ->helperText(__('The extracted value will be compared to this field on the member record.')),
                        Placeholder::make('member_match_hint')
                            ->hiddenLabel()
                            ->content(__('Examples: /Account[:\s]+(?P<member>\d+)/i matches member number · /Name[:\s]+(?P<member>[A-Za-z\s]+)/i matches by name')),
                    ])->columns(2),
                ]),

                Tab::make(__('Duplicate Detection'))->schema([
                    CheckboxList::make('duplicate_match_fields')
                        ->label(__('Match duplicates on these fields'))
                        ->options(Lang::transOptions([
                            'date' => __('Transaction date'),
                            'amount' => __('Amount'),
                            'type' => __('Transaction type (credit / debit)'),
                            'reference' => __('Reference number'),
                            'raw_sms' => __('Exact SMS text'),
                        ]))
                        ->default(['date', 'amount', 'reference'])
                        ->columns(2)
                        ->helperText(__('A message is flagged as a duplicate only when ALL selected fields match an existing record.')),
                    TextInput::make('duplicate_date_tolerance')
                        ->label(__('Date tolerance (days)'))
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(30)
                        ->helperText(__('Allow this many days difference when matching by date (0 = exact match).')),
                ]),
            ])->columnSpanFull(),
        ]);
    }
}
