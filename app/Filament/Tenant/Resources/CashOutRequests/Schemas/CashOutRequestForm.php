<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\CashOutRequests\Schemas;

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\MemberCashOutService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class CashOutRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema
            ->components([
                Section::make(__('Cash-out details'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('member_id')
                            ->label(__('Member'))
                            ->options(Member::active()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->live(),
                        Placeholder::make('availability')
                            ->label(__('Available to withdraw'))
                            ->content(function (MemberCashOutService $service, Get $get): HtmlString {
                                $memberId = $get('member_id');

                                if (! filled($memberId)) {
                                    return new HtmlString(
                                        '<span class="text-gray-500">'.e(__('Select a member to see available cash.')).'</span>'
                                    );
                                }

                                $member = Member::query()->find($memberId);

                                if ($member === null) {
                                    return new HtmlString(
                                        '<span class="text-gray-500">'.e(__('Member not found.')).'</span>'
                                    );
                                }

                                $available = $service->availableCashForWithdrawal($member);
                                $reserved = $service->reservedForNextEmi($member);

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
                            ->prefix($currency)
                            ->helperText(__('Cash out draws from the member cash account, not the fund account.')),
                        Textarea::make('notes')
                            ->label(__('Notes'))
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder(__('Optional instructions for the record...'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
