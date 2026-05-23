<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundAuditLogs\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FundAuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Event'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('event_type')->label(__('Event type')),
                        TextEntry::make('domain')->label(__('Domain')),
                        TextEntry::make('occurred_at')->dateTime()->label(__('Occurred at')),
                        TextEntry::make('checksum')->label(__('Checksum'))->placeholder(__('—')),
                        TextEntry::make('member.name')->label(__('Member'))->placeholder(__('—')),
                        TextEntry::make('operator.name')->label(__('Operator'))->placeholder(__('—')),
                        TextEntry::make('payload')
                            ->label(__('Payload'))
                            ->formatStateUsing(fn ($state): string => json_encode($state ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
