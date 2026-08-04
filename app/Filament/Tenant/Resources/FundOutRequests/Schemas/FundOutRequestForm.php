<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundOutRequests\Schemas;

use App\Filament\Support\MemberFilamentActions;
use App\Filament\Support\MemberSelect;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\MemberFundOutService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class FundOutRequestForm
{
    /**
     * @return list<Component|\Filament\Forms\Components\Component>
     */
    public static function components(bool $lockMember = false): array
    {
        $currency = Setting::get('general', 'currency', 'USD');

        $memberField = MemberSelect::make('member_id')
            ->required()
            ->live();

        if ($lockMember) {
            $memberField = $memberField
                ->disabled()
                ->dehydrated();
        }

        return [
            $memberField,
            Placeholder::make('availability')
                ->label(__('Available fund balance'))
                ->content(function (MemberFundOutService $service, Get $get): HtmlString {
                    $memberId = $get('member_id');

                    if (! filled($memberId)) {
                        return new HtmlString(
                            '<span class="text-gray-500">'.e(__('Select a member to see available fund balance.')).'</span>'
                        );
                    }

                    $member = Member::query()->find($memberId);

                    if ($member === null) {
                        return new HtmlString(
                            '<span class="text-gray-500">'.e(__('Member not found.')).'</span>'
                        );
                    }

                    $available = $service->availableFundForTransfer($member);

                    return new HtmlString(
                        '<div class="space-y-1 text-sm">'
                        .e(__('Available: :amount', ['amount' => number_format($available, 2)]))
                        .'</div>'
                    );
                }),
            TextInput::make('amount')
                ->label(__('Transfer amount'))
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->prefix($currency)
                ->helperText(__('Fund out moves money from the member fund account to their cash account. It does not create a bank remittance.')),
            MemberFilamentActions::fundOutDateField(),
            Textarea::make('notes')
                ->label(__('Notes'))
                ->rows(3)
                ->maxLength(1000)
                ->placeholder(__('Optional instructions for the record...'))
                ->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Fund-out details'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema(self::components()),
            ]);
    }
}
