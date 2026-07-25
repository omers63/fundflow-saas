<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberCashTransferRequests\Schemas;

use App\Filament\Support\MemberSelect;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\MemberCashTransferService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class MemberCashTransferRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema
            ->components([
                Section::make(__('Cash transfer'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        MemberSelect::make('from_member_id')
                            ->label(__('From member'))
                            ->required()
                            ->live(),
                        MemberSelect::make('to_member_id')
                            ->label(__('To member'))
                            ->required()
                            ->live()
                            ->disableOptionWhen(fn (mixed $value, Get $get): bool => (int) $value === (int) ($get('from_member_id') ?? 0)),
                        Placeholder::make('availability')
                            ->label(__('Available to transfer'))
                            ->content(function (MemberCashTransferService $service, Get $get) use ($currency): HtmlString {
                                $memberId = $get('from_member_id');

                                if (! filled($memberId)) {
                                    return new HtmlString(
                                        '<span class="text-gray-500">'.e(__('Select the source member.')).'</span>'
                                    );
                                }

                                $member = Member::query()->find($memberId);
                                $available = $member !== null ? $service->availableCashForTransfer($member) : 0.0;

                                return new HtmlString(e(number_format($available, 2).' '.$currency));
                            })
                            ->columnSpanFull(),
                        TextInput::make('amount')
                            ->label(__('Amount'))
                            ->numeric()
                            ->required()
                            ->minValue(0.01),
                        Textarea::make('notes')
                            ->label(__('Notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
