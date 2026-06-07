<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Lang;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;

final class SmsImportTemplateFieldsets
{
    /**
     * Collapsible repeater fieldsets for Settings (matches bank CSV template layout).
     *
     * @return array<int, Fieldset>
     */
    public static function forSettingsRepeater(): array
    {
        return [
            Fieldset::make(__('General'))
                ->columns(3)
                ->schema([
                    Hidden::make('id'),
                    TextInput::make('bank_name')
                        ->label(__('Bank name (optional)'))
                        ->maxLength(100)
                        ->helperText(__('When set, duplicate detection can be scoped to imports for this bank label.')),
                    TextInput::make('name')
                        ->label(__('Template name'))
                        ->required()
                        ->maxLength(100)
                        ->placeholder(__('e.g. Al-Rajhi SMS Export v1')),
                    Checkbox::make('is_default')
                        ->label(__('Default template')),
                    Select::make('default_transaction_type')
                        ->label(__('Default type when no keyword matches'))
                        ->options(Lang::transOptions([
                            'credit' => __('Credit'),
                            'debit' => __('Debit'),
                        ]))
                        ->default('credit')
                        ->required(),
                    Select::make('delimiter')
                        ->label(__('Delimiter'))
                        ->options(Lang::transOptions([
                            ',' => __('Comma (, )'),
                            ';' => __('Semicolon (;)'),
                            "\t" => __('Tab'),
                            '|' => __('Pipe (|)'),
                        ]))
                        ->required()
                        ->default(','),
                    Select::make('encoding')
                        ->label(__('File encoding'))
                        ->options([
                            'UTF-8' => 'UTF-8',
                            'ISO-8859-1' => 'ISO-8859-1 (Latin-1)',
                            'Windows-1256' => 'Windows-1256 (Arabic)',
                            'Windows-1252' => 'Windows-1252 (Western)',
                        ])
                        ->required()
                        ->default('UTF-8'),
                    TextInput::make('skip_rows')
                        ->label(__('Skip rows'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->helperText(__('Rows to skip before the header row (if enabled) or data.')),
                ]),

            Fieldset::make(__('Header and columns'))
                ->columns(2)
                ->schema([
                    Toggle::make('has_header')
                        ->label(__('First data row is a header'))
                        ->helperText(__('On: use exact column header text in mappings. Off: use 0-based column index (0, 1, 2...).'))
                        ->default(true)
                        ->columnSpanFull(),
                    TextInput::make('sms_column')
                        ->label(__('SMS text column'))
                        ->required()
                        ->helperText(__('Header name or 0-based index of the column that contains the raw SMS message.')),
                    TextInput::make('date_column')
                        ->label(__('Date column (optional)'))
                        ->helperText(__('Leave blank to extract the date from the SMS text using the pattern below.')),
                    TextInput::make('date_format')
                        ->label(__('Date format (PHP)'))
                        ->default('Y-m-d H:i:s')
                        ->helperText(__('e.g. Y-m-d H:i:s · d/m/Y · d-M-Y · m/d/Y H:i')),
                ]),

            Fieldset::make(__('SMS parsing rules'))
                ->columns(2)
                ->schema([
                    TextInput::make('amount_pattern')
                        ->label(__('Amount regex pattern'))
                        ->placeholder('/SAR\s*(?P<amount>[\d,]+\.?\d*)/i')
                        ->helperText(__('Named capture group "amount". Thousands commas are stripped automatically.'))
                        ->columnSpanFull(),
                    TextInput::make('date_pattern')
                        ->label(__('Date regex pattern'))
                        ->placeholder('/on\s+(?P<date>\d{2}\/\d{2}\/\d{4})/i')
                        ->helperText(__('Named capture group "date". Used when no date column is mapped.'))
                        ->columnSpanFull(),
                    TextInput::make('date_pattern_format')
                        ->label(__('Date format for extracted value (PHP)'))
                        ->placeholder('d/m/Y'),
                    TextInput::make('reference_pattern')
                        ->label(__('Reference regex pattern'))
                        ->placeholder('/[Rr]ef[:\s]+(?P<reference>\d+)/')
                        ->helperText(__('Named capture group "reference".'))
                        ->columnSpanFull(),
                    TagsInput::make('credit_keywords')
                        ->label(__('Credit keywords'))
                        ->default(['credited', 'received', 'deposit', 'credit'])
                        ->afterStateHydrated(function (TagsInput $component, mixed $state): void {
                            if (!is_array($state)) {
                                $component->state(['credited', 'received', 'deposit', 'credit']);
                            }
                        })
                        ->separator(','),
                    TagsInput::make('debit_keywords')
                        ->label(__('Debit keywords'))
                        ->default(['debited', 'paid', 'purchase', 'debit', 'withdraw'])
                        ->afterStateHydrated(function (TagsInput $component, mixed $state): void {
                            if (!is_array($state)) {
                                $component->state(['debited', 'paid', 'purchase', 'debit', 'withdraw']);
                            }
                        })
                        ->separator(','),
                ]),

            Fieldset::make(__('Member auto-match'))
                ->columns(2)
                ->schema([
                    TextInput::make('member_match_pattern')
                        ->label(__('Member regex pattern'))
                        ->placeholder('/Account[:\s]+(?P<member>\d+)/i')
                        ->helperText(__('Named capture group "member".'))
                        ->columnSpanFull(),
                    Select::make('member_match_field')
                        ->label(__('Match against'))
                        ->options(Lang::transOptions([
                            'member_number' => __('Member number'),
                            'member_name' => __('Member full name'),
                            'user_name' => __('User full name (legacy alias)'),
                        ]))
                        ->default('member_number'),
                ]),

            Fieldset::make(__('Duplicate detection'))
                ->columns(2)
                ->schema([
                    CheckboxList::make('duplicate_match_fields')
                        ->label(__('Match fields'))
                        ->options(Lang::transOptions([
                            'date' => __('Transaction date'),
                            'amount' => __('Amount'),
                            'type' => __('Transaction type (credit / debit)'),
                            'reference' => __('Reference number'),
                            'raw_sms' => __('Exact SMS text'),
                        ]))
                        ->default(['date', 'amount', 'reference'])
                        ->afterStateHydrated(function (CheckboxList $component, mixed $state): void {
                            if (!is_array($state)) {
                                $component->state(['date', 'amount', 'reference']);
                            }
                        })
                        ->columns(2)
                        ->columnSpanFull(),
                    TextInput::make('duplicate_date_tolerance')
                        ->label(__('Date tolerance (days)'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(30)
                        ->default(0)
                        ->helperText(__('0 = exact date match.')),
                ]),
        ];
    }
}
