<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\NotificationLogs\Schemas;

use App\Models\Tenant\NotificationLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NotificationLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Recipient'))
                ->columns(3)
                ->schema([
                    TextEntry::make('user.name')->label(__('Name'))->placeholder(__('—')),
                    TextEntry::make('user.email')->label(__('Email'))->placeholder(__('—')),
                    TextEntry::make('channel')
                        ->badge()
                        ->color(fn (?string $state): string => match ($state) {
                            'mail' => 'primary',
                            'database' => 'info',
                            'twilio' => 'success',
                            'whatsapp' => 'warning',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            'mail' => __('Email'),
                            'database' => __('In-app'),
                            'twilio' => __('SMS'),
                            'whatsapp' => __('WhatsApp'),
                            default => $state ?? '—',
                        }),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (?string $state): string => match ($state) {
                            'sent' => 'success',
                            'failed' => 'danger',
                            'skipped' => 'gray',
                            default => 'gray',
                        }),
                    TextEntry::make('sent_at')->dateTime('d M Y H:i')->placeholder(__('—')),
                ]),

            Section::make(__('Content'))
                ->schema([
                    TextEntry::make('subject')->columnSpanFull(),
                    TextEntry::make('body')
                        ->label(__('Message body'))
                        ->html()
                        ->columnSpanFull(),
                ]),

            Section::make(__('Error details'))
                ->schema([
                    TextEntry::make('error_message')
                        ->label(__('Error'))
                        ->color('danger')
                        ->columnSpanFull(),
                ])
                ->visible(fn (NotificationLog $record): bool => filled($record->error_message))
                ->collapsible(),
        ]);
    }
}
