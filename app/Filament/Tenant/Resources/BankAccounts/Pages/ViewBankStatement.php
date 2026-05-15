<?php

namespace App\Filament\Tenant\Resources\BankAccounts\Pages;

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\TextEntry;
use Filament\Schemas\Schema;

class ViewBankStatement extends ViewRecord
{
    protected static string $resource = BankAccountsResource::class;

    public function getHeading(): string
    {
        return $this->record->filename;
    }

    public function schema(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('Statement Details'))
                ->columns(3)
                ->schema([
                    TextEntry::make('filename'),
                    TextEntry::make('bank_name')
                        ->placeholder(__('—')),
                    TextEntry::make('statement_date')
                        ->date(),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'pending' => 'warning',
                            'processing' => 'info',
                            'completed' => 'success',
                            'failed' => 'danger',
                        }),
                    TextEntry::make('total_rows'),
                    TextEntry::make('imported_rows'),
                    TextEntry::make('duplicate_rows'),
                    TextEntry::make('imported_at')
                        ->dateTime(),
                    TextEntry::make('notes')
                        ->placeholder(__('No notes'))
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
