<?php

declare(strict_types=1);

namespace App\Filament\Support\ViewActions;

use App\Filament\Support\LateSettledArrearsTableStyling;
use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Setting;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

final class ViewContributionAction
{
    /**
     * @return array<string, mixed>
     */
    public static function formatRecordData(Contribution $record): array
    {
        $record->loadMissing('member');

        $currency = Setting::get('general', 'currency', 'USD');

        return [
            ...$record->attributesToArray(),
            'member_name' => $record->member->name,
            'period_display' => $record->period?->translatedFormat('F Y') ?? __('—'),
            'amount_display' => MoneyDisplay::format((float) $record->amount, $currency),
            'amount_due_display' => MoneyDisplay::format((float) ($record->amount_due ?? $record->amount), $currency),
            'amount_collected_display' => MoneyDisplay::format((float) ($record->amount_collected ?? 0), $currency),
            'late_fee_amount_display' => MoneyDisplay::format((float) ($record->late_fee_amount ?? 0), $currency),
            'status_display' => LateSettledArrearsTableStyling::contributionStatusLabel($record),
            'collection_status_display' => $record->collection_status
                ? ucfirst(str_replace('_', ' ', (string) $record->collection_status))
                : __('—'),
            'payment_method_display' => Contribution::paymentMethodOptions()[$record->payment_method]
                ?? ucfirst(str_replace('_', ' ', (string) $record->payment_method)),
            'posted_at_display' => $record->posted_at?->format('M j, Y g:i A') ?? __('—'),
            'paid_at_display' => $record->paid_at?->format('M j, Y g:i A') ?? __('—'),
            'reference_display' => $record->reference_number ?: __('—'),
            'notes_display' => $record->notes ?: __('—'),
            'is_late_display' => $record->is_late ? __('Yes') : __('No'),
        ];
    }

    public static function make(): ViewAction
    {
        return ViewAction::make()
            ->modalWidth('2xl')
            ->modalHeading(fn (Contribution $record): string => __('Contribution — :name', [
                'name' => $record->member->name,
            ]))
            ->mutateRecordDataUsing(function (array $data, Contribution $record): array {
                return self::formatRecordData($record);
            })
            ->schema(self::schema());
    }

    /**
     * @return array<int, Section>
     */
    public static function schema(): array
    {
        return [
            Section::make(__('Contribution'))
                ->columns(2)
                ->schema(self::disabledFields([
                    TextInput::make('member_name')
                        ->label(__('Member')),
                    TextInput::make('period_display')
                        ->label(__('Period')),
                    TextInput::make('amount_display')
                        ->label(__('Amount')),
                    TextInput::make('status_display')
                        ->label(__('Status')),
                    TextInput::make('payment_method_display')
                        ->label(__('Source')),
                    TextInput::make('collection_status_display')
                        ->label(__('Collection status')),
                ])),
            Section::make(__('Settlement'))
                ->columns(2)
                ->schema(self::disabledFields([
                    TextInput::make('amount_due_display')
                        ->label(__('Amount due')),
                    TextInput::make('amount_collected_display')
                        ->label(__('Amount collected')),
                    TextInput::make('late_fee_amount_display')
                        ->label(__('Late fee')),
                    TextInput::make('is_late_display')
                        ->label(__('Late flag')),
                    TextInput::make('posted_at_display')
                        ->label(__('Posted at')),
                    TextInput::make('paid_at_display')
                        ->label(__('Paid at')),
                ])),
            Section::make(__('Reference'))
                ->schema(self::disabledFields([
                    TextInput::make('reference_display')
                        ->label(__('Reference number'))
                        ->columnSpanFull(),
                    Textarea::make('notes_display')
                        ->label(__('Notes'))
                        ->rows(3)
                        ->columnSpanFull(),
                ])),
        ];
    }

    /**
     * @param  array<int, Component>  $fields
     * @return array<int, Component>
     */
    private static function disabledFields(array $fields): array
    {
        return array_map(
            fn (Component $field): Component => $field->disabled()->dehydrated(false),
            $fields,
        );
    }
}
