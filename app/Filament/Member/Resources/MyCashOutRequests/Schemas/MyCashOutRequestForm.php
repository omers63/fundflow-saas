<?php

namespace App\Filament\Member\Resources\MyCashOutRequests\Schemas;

use App\Services\MemberCashOutService;
use App\Support\Tenant\CurrentMember;
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
                        $available = $member !== null ? $service->availableCashForWithdrawal($member) : 0.0;
                        $reserved = $member !== null ? $service->reservedForNextEmi($member) : 0.0;

                        return new HtmlString(
                            '<div class="space-y-1 text-sm">'
                            .e(__('Available: :amount', ['amount' => number_format($available, 2)]))
                            .'<br><span class="text-gray-500">'
                            .e(__('Reserved for next EMI: :amount', ['amount' => number_format($reserved, 2)]))
                            .'</span></div>'
                        );
                    }),
                TextInput::make('amount')
                    ->label(__('Withdrawal amount'))
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->helperText(__('Cash out draws from your cash account, not your fund account.')),
                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->rows(3)
                    ->maxLength(1000)
                    ->placeholder(__('Optional instructions for the admin...')),
            ]);
    }
}
