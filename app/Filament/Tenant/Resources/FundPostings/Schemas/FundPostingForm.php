<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundPostings\Schemas;

use App\Filament\Support\MemberSelect;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Support\BusinessDay;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FundPostingForm
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema
            ->components([
                Section::make(__('Deposit details'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        MemberSelect::make('member_id')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set): void {
                                $member = Member::query()->find($state);

                                if ($member !== null) {
                                    $set('reference', $member->name);
                                }
                            }),
                        DatePicker::make('posting_date')
                            ->label(__('Date of transfer'))
                            ->required()
                            ->default(BusinessDay::now())
                            ->maxDate(BusinessDay::now()),
                        TextInput::make('amount')
                            ->label(__('Amount'))
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->prefix($currency),
                        TextInput::make('reference')
                            ->label(__('Reference / receipt number'))
                            ->maxLength(255)
                            ->placeholder(__('e.g. bank transfer reference'))
                            ->columnSpanFull(),
                        FileUpload::make('attachment')
                            ->label(__('Attachment (e.g. transfer receipt)'))
                            ->disk('public')
                            ->directory('fund-postings')
                            ->acceptedFileTypes(['image/*', 'application/pdf'])
                            ->maxSize(5120)
                            ->columnSpanFull(),
                        Textarea::make('comments')
                            ->label(__('Comments / instructions'))
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder(__('Any additional notes for the record.'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
