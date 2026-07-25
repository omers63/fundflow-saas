<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyCashTransfers\Schemas;

use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\Member;
use App\Services\MemberCashOutService;
use App\Services\MemberCashTransferService;
use App\Support\Insights\InsightFormatter;
use App\Support\Tenant\CurrentMember;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class MyCashTransferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('availability')
                    ->label(__('Available to transfer'))
                    ->content(function (MemberCashTransferService $service): HtmlString {
                        $member = CurrentMember::get();
                        $currency = InsightFormatter::currency();
                        $available = $member !== null ? $service->availableCashForTransfer($member) : 0.0;
                        $cashBalance = $member?->getCashBalance() ?? 0.0;
                        $reserved = $member !== null
                            ? app(MemberCashOutService::class)->reservedForNextEmi($member)
                            : 0.0;
                        $availableHtml = MoneyDisplay::html($available, $currency)?->toHtml() ?? '—';
                        $cashHtml = MoneyDisplay::html($cashBalance, $currency)?->toHtml() ?? '—';

                        $html = '<div class="space-y-1 text-sm">'
                            .__('Available: :amount', ['amount' => $availableHtml]);

                        if ($reserved > 0.00001) {
                            $reservedHtml = MoneyDisplay::html($reserved, $currency)?->toHtml() ?? '—';
                            $html .= '<br><span class="text-gray-500">'
                                .__('Cash balance: :cash · Next EMI reserved for cash-out only: :reserved', [
                                    'cash' => $cashHtml,
                                    'reserved' => $reservedHtml,
                                ])
                                .'</span>';
                        }

                        $html .= '</div>';

                        return new HtmlString($html);
                    }),
                Select::make('transfer_mode')
                    ->label(__('Transfer to'))
                    ->options(fn (): array => self::transferModeOptions())
                    ->default(fn (): string => self::defaultTransferMode())
                    ->required()
                    ->live()
                    ->native(false),
                Select::make('to_member_id')
                    ->label(__('Dependent'))
                    ->options(fn (): array => self::dependentOptions())
                    ->required(fn (Get $get): bool => $get('transfer_mode') === 'dependent')
                    ->visible(fn (Get $get): bool => $get('transfer_mode') === 'dependent')
                    ->searchable()
                    ->native(false)
                    ->helperText(__('Transfers to your dependents are completed immediately — no admin approval.')),
                TextInput::make('recipient_name')
                    ->label(__('Recipient member name'))
                    ->required(fn (Get $get): bool => $get('transfer_mode') !== 'dependent')
                    ->visible(fn (Get $get): bool => $get('transfer_mode') !== 'dependent')
                    ->maxLength(255)
                    ->helperText(__('Enter the full name of the member who should receive the cash. An administrator will confirm the match.')),
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->numeric()
                    ->required()
                    ->minValue(0.01),
                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->rows(2)
                    ->maxLength(500),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function transferModeOptions(): array
    {
        $options = [
            'other' => __('Another member (admin approval)'),
        ];

        if (self::dependentOptions() !== []) {
            $options = [
                'dependent' => __('My dependent (instant)'),
                ...$options,
            ];
        }

        return $options;
    }

    private static function defaultTransferMode(): string
    {
        return self::dependentOptions() !== [] ? 'dependent' : 'other';
    }

    /**
     * @return array<int, string>
     */
    private static function dependentOptions(): array
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return [];
        }

        return $member->dependents()
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Member $dependent): array => [
                $dependent->id => $dependent->member_number.' — '.$dependent->name,
            ])
            ->all();
    }
}
