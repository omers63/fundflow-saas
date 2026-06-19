<?php

namespace App\Filament\Member\Resources\MyFundPostings\Schemas;

use App\Models\Tenant\Setting;
use App\Support\BusinessDay;
use App\Support\Tenant\CurrentMember;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MyFundPostingForm
{
    public static function configure(Schema $schema): Schema
    {
        $currency = fn (): string => Setting::get('general', 'currency', 'USD');

        return $schema
            ->components([
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
                    ->prefix(fn (): string => $currency()),
                TextInput::make('reference')
                    ->label(__('Reference / receipt number'))
                    ->default(fn (): ?string => CurrentMember::get()?->name)
                    ->maxLength(255)
                    ->placeholder(__('e.g. bank transfer reference')),
                FileUpload::make('attachment')
                    ->label(__('Attachment (e.g. transfer receipt)'))
                    ->disk('public')
                    ->directory('fund-postings')
                    ->acceptedFileTypes(['image/*', 'application/pdf'])
                    ->maxSize(5120),
                Textarea::make('comments')
                    ->label(__('Comments / instructions'))
                    ->rows(3)
                    ->maxLength(1000)
                    ->placeholder(__('Any additional notes for the admin...')),
            ]);
    }
}
