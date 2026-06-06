<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsImportSessions\Schemas;

use App\Models\Tenant\SmsImportSession;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SmsImportSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Import session'))
                ->columns(2)
                ->schema([
                    TextEntry::make('bank_name')
                        ->label(__('Bank'))
                        ->placeholder(__('—')),
                    TextEntry::make('filename'),
                    TextEntry::make('template.name')
                        ->label(__('Template'))
                        ->placeholder(__('—')),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'completed' => 'success',
                            'processing' => 'warning',
                            'partially_completed' => 'warning',
                            'failed' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('total_rows')
                        ->label(__('Rows')),
                    TextEntry::make('imported_count')
                        ->label(__('Imported')),
                    TextEntry::make('duplicate_count')
                        ->label(__('Duplicates')),
                    TextEntry::make('error_count')
                        ->label(__('Errors')),
                    TextEntry::make('importer.name')
                        ->label(__('Imported by'))
                        ->placeholder(__('—')),
                    TextEntry::make('created_at')
                        ->dateTime('d M Y H:i'),
                    TextEntry::make('completed_at')
                        ->dateTime('d M Y H:i')
                        ->placeholder(__('—')),
                    TextEntry::make('notes')
                        ->columnSpanFull()
                        ->placeholder(__('—')),
                ]),

            Section::make(__('Error log'))
                ->schema([
                    KeyValueEntry::make('error_log')
                        ->label('')
                        ->columnSpanFull(),
                ])
                ->visible(fn (SmsImportSession $record): bool => filled($record->error_log))
                ->collapsible(),
        ]);
    }
}
