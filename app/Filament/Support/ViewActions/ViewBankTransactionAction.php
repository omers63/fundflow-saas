<?php

namespace App\Filament\Support\ViewActions;

use App\Filament\Support\BankTransactionImportFields;
use App\Filament\Support\MoneyDisplay;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Setting;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Tables\Table;

final class ViewBankTransactionAction
{
    public static function make(): ViewAction
    {
        return ViewAction::make()
            ->modalWidth('xl')
            ->modalHeading(fn (BankTransaction $record): string => filled($record->description)
                ? $record->description
                : __('Bank transaction #:id', ['id' => $record->id]))
            ->mutateRecordDataUsing(function (array $data, BankTransaction $record): array {
                $record->loadMissing(['bankStatement.bankTemplate', 'member', 'duplicateOf']);

                $currency = Setting::get('general', 'currency', 'USD');

                return [
                    ...$data,
                    'transaction_date_display' => $record->transaction_date?->format('M j, Y'),
                    'amount_display' => MoneyDisplay::format((float) $record->amount, $currency),
                    'source_statement' => $record->bankStatement?->filename,
                    'template_name' => $record->bankStatement?->bankTemplate?->name
                        ?? __('Default template'),
                    'member_name' => $record->member?->name,
                    'duplicate_of_description' => $record->duplicateOf?->description,
                    'cleared_display' => $record->is_cleared ? __('Yes') : __('No'),
                    'cleared_at_display' => $record->cleared_at?->format('M j, Y g:i A'),
                    'created_at_display' => $record->created_at?->format('M j, Y g:i A'),
                    'updated_at_display' => $record->updated_at?->format('M j, Y g:i A'),
                    'master_cash_mirror_summary' => $record->masterCashMirrorSummary() ?? __('Not mirrored yet'),
                    'import_field_rows' => BankTransactionImportFields::labeledRows($record),
                ];
            })
            ->schema(self::schema());
    }

    /**
     * @return array<int, Section>
     */
    public static function schema(): array
    {
        return [
            Section::make(__('Transaction details'))
                ->columns(2)
                ->schema([
                    TextInput::make('id')
                        ->label(__('Transaction ID')),
                    TextInput::make('transaction_date_display')
                        ->label(__('Date')),
                    TextInput::make('amount_display')
                        ->label(__('Amount')),
                    TextInput::make('transaction_type')
                        ->label(__('Type'))
                        ->placeholder(__('—')),
                    TextInput::make('status')
                        ->label(__('Status')),
                    TextInput::make('reference')
                        ->label(__('Reference'))
                        ->placeholder(__('—')),
                    TextInput::make('source_statement')
                        ->label(__('Source statement'))
                        ->placeholder(__('—')),
                    TextInput::make('template_name')
                        ->label(__('Import template'))
                        ->placeholder(__('—')),
                    TextInput::make('member_name')
                        ->label(__('Member'))
                        ->placeholder(__('Unassigned')),
                    TextInput::make('duplicate_of_description')
                        ->label(__('Duplicate of'))
                        ->placeholder(__('—'))
                        ->columnSpanFull(),
                    Textarea::make('description')
                        ->label(__('Description'))
                        ->placeholder(__('—'))
                        ->rows(3)
                        ->columnSpanFull(),
                    TextInput::make('cleared_display')
                        ->label(__('Cleared')),
                    TextInput::make('cleared_at_display')
                        ->label(__('Cleared at'))
                        ->placeholder(__('—')),
                    TextInput::make('created_at_display')
                        ->label(__('Imported')),
                    TextInput::make('updated_at_display')
                        ->label(__('Updated')),
                    TextInput::make('master_cash_mirror_summary')
                        ->label(__('Master cash mirror'))
                        ->columnSpanFull(),
                ]),
            Section::make(__('Template column mapping'))
                ->description(__('Values as mapped from the CSV using the import template.'))
                ->schema([
                    KeyValue::make('import_field_rows')
                        ->hiddenLabel()
                        ->disabled()
                        ->columnSpanFull(),
                ]),
        ];
    }

    public static function configure(Table $table): Table
    {
        return TableRecordActionGroups::apply(
            TableGrouping::apply($table, TableGrouping::bankTransactions()),
            [self::make()],
        );
    }
}
