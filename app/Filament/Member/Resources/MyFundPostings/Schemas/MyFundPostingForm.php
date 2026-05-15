<?php

namespace App\Filament\Member\Resources\MyFundPostings\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MyFundPostingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('posting_date')
                    ->label('Date of Transfer')
                    ->required()
                    ->default(now())
                    ->maxDate(now()),
                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->prefix('$'),
                TextInput::make('reference')
                    ->label('Reference / Receipt Number')
                    ->maxLength(255)
                    ->placeholder(__('e.g. bank transfer reference')),
                FileUpload::make('attachment')
                    ->label('Attachment (e.g. transfer receipt)')
                    ->disk('public')
                    ->directory('fund-postings')
                    ->acceptedFileTypes(['image/*', 'application/pdf'])
                    ->maxSize(5120),
                Textarea::make('comments')
                    ->label('Comments / Instructions')
                    ->rows(3)
                    ->maxLength(1000)
                    ->placeholder(__('Any additional notes for the admin...')),
            ]);
    }
}
