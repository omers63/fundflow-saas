<?php

namespace App\Filament\Member\Resources\MyCashOutRequests\Schemas;

use App\Filament\Support\MoneyDisplay;
use App\Services\MemberCashOutService;
use App\Support\Insights\InsightFormatter;
use App\Support\Tenant\CurrentMember;
use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class MyCashOutRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('availability')
                    ->label(__('Available to withdraw'))
                    ->content(function (MemberCashOutService $service): HtmlString {
                        $member = CurrentMember::get();
                        $currency = InsightFormatter::currency();
                        $available = $member !== null ? $service->availableCashForWithdrawal($member) : 0.0;
                        $reserved = $member !== null ? $service->reservedForNextEmi($member) : 0.0;
                        $availableHtml = MoneyDisplay::html($available, $currency)?->toHtml() ?? '—';
                        $reservedHtml = MoneyDisplay::html($reserved, $currency)?->toHtml() ?? '—';

                        return new HtmlString(
                            '<div class="space-y-1 text-sm">'
                            .__('Available: :amount', ['amount' => $availableHtml])
                            .'<br><span class="text-gray-500">'
                            .__('Reserved for next EMI: :amount', ['amount' => $reservedHtml])
                            .'</span></div>'
                        );
                    }),
                TextInput::make('amount')
                    ->label(__('Withdrawal amount'))
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->rules([
                        fn (MemberCashOutService $service): Closure => function (string $attribute, mixed $value, Closure $fail) use ($service): void {
                            $member = CurrentMember::get();

                            if ($member === null) {
                                return;
                            }

                            $available = $service->availableCashForWithdrawal($member);

                            if ((float) $value > $available + 0.01) {
                                $fail(__('Amount exceeds available cash (:available).', [
                                    'available' => number_format($available, 2),
                                ]));
                            }
                        },
                    ])
                    ->helperText(__('Cash out draws from your cash account, not your fund account.')),
                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->rows(3)
                    ->maxLength(1000)
                    ->placeholder(__('Optional instructions for the admin...')),
            ]);
    }
}
